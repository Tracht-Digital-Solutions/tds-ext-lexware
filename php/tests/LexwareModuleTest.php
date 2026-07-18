<?php
declare(strict_types=1);

namespace Tds\Ext\Lexware\Tests;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\Ext\Lexware\LexwareModule;
use Tds\Panel\Contract\UserContext;

/** A configurable UserContext double (no live JWT needed). */
final class FakeUser implements UserContext
{
    /** @param string[] $perms */
    public function __construct(
        private bool $auth = true,
        private bool $admin = false,
        private array $perms = [],
        private ?int $company = null,
        private ?int $uid = 1,
    ) {
    }

    public function isAuthenticated(): bool
    {
        return $this->auth;
    }

    public function userId(): ?int
    {
        return $this->uid;
    }

    public function email(): ?string
    {
        return null;
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    /** @return string[] */
    public function permissions(): array
    {
        return $this->perms;
    }

    public function has(string $permission): bool
    {
        return $this->admin || in_array($permission, $this->perms, true);
    }

    public function activeCompanyId(): ?int
    {
        return $this->company;
    }
}

/**
 * Route + RBAC coverage that needs no DB: the auth checks (and payload
 * validation) short-circuit before any repository or HTTP-client access, so
 * these run without a bound PDO. Full data paths are covered by the DB-gated
 * repository test.
 */
final class LexwareModuleTest extends TestCase
{
    private function appWith(UserContext $user): App
    {
        $container = new Container();
        $container->set(UserContext::class, $user);
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        (new LexwareModule())->register($app);
        return $app;
    }

    private function get(App $app, string $path): \Psr\Http\Message\ResponseInterface
    {
        return $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
    }

    /** @param array<string,mixed> $body */
    private function post(App $app, string $path, array $body): \Psr\Http\Message\ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', $path)
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody($body);
        return $app->handle($req);
    }

    public function testMetadata(): void
    {
        $module = new LexwareModule();
        self::assertSame('lexware', $module->id());
        $perms = array_map(static fn ($p): string => $p->id, $module->permissions());
        self::assertSame(['lexware:read', 'lexware:write'], $perms);
        self::assertDirectoryExists($module->migrations()[0]);
        $settingKeys = array_map(static fn ($s): string => $s->key, $module->settings());
        self::assertSame(['api_key', 'api_url', 'default_hourly_rate', 'default_tax_rate'], $settingKeys);
    }

    public function testUnauthenticatedGetsUnauthorized(): void
    {
        self::assertSame(401, $this->get($this->appWith(new FakeUser(auth: false)), '/lexware/customers')->getStatusCode());
        self::assertSame(401, $this->get($this->appWith(new FakeUser(auth: false)), '/lexware/summary')->getStatusCode());
    }

    public function testReadWithoutPermissionForbidden(): void
    {
        self::assertSame(403, $this->get($this->appWith(new FakeUser(perms: [])), '/lexware/customers')->getStatusCode());
        self::assertSame(403, $this->get($this->appWith(new FakeUser(perms: [])), '/lexware/invoices')->getStatusCode());
    }

    public function testCreateCustomerRequiresWrite(): void
    {
        $res = $this->post($this->appWith(new FakeUser(perms: ['lexware:read'])), '/lexware/customers', ['name' => 'ACME']);
        self::assertSame(403, $res->getStatusCode());
    }

    public function testCreateCustomerValidatesName(): void
    {
        // write permission passes; the empty name is rejected before any DB access.
        $res = $this->post($this->appWith(new FakeUser(perms: ['lexware:write'])), '/lexware/customers', ['name' => '']);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testTimeAssignValidatesIds(): void
    {
        $res = $this->post($this->appWith(new FakeUser(perms: ['lexware:write'])), '/lexware/time/assign', []);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testLeadPushValidatesPayload(): void
    {
        $res = $this->post($this->appWith(new FakeUser(perms: ['lexware:write'])), '/lexware/leads/push', ['source_type' => 'bogus']);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testInvoiceExportRequiresWrite(): void
    {
        $res = $this->post($this->appWith(new FakeUser(perms: ['lexware:read'])), '/lexware/invoices/from-project', ['projectId' => 1]);
        self::assertSame(403, $res->getStatusCode());
    }

    public function testConnectionTestRequiresAdmin(): void
    {
        $res = $this->get($this->appWith(new FakeUser(perms: ['lexware:read', 'lexware:write'])), '/lexware/admin/test');
        self::assertSame(403, $res->getStatusCode());
    }
}
