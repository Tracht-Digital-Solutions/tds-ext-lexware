<?php
declare(strict_types=1);

namespace Tds\Ext\Lexware;

use DateTimeImmutable;
use PDO;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Tds\Ext\Lexware\Domain\ContactMapRepository;
use Tds\Ext\Lexware\Domain\CustomerRepository;
use Tds\Ext\Lexware\Domain\InvoiceLogRepository;
use Tds\Ext\Lexware\Domain\TimeLinkRepository;
use Tds\Ext\Lexware\Service\LexwareClient;
use Tds\Ext\Lexware\Service\LexwareContactBuilder;
use Tds\Ext\Lexware\Service\LexwareException;
use Tds\Ext\Lexware\Service\LexwareInvoiceBuilder;
use Tds\Ext\Lexware\Service\SourceGateway;
use Tds\Frontend\Contract\AbstractModule;
use Tds\Frontend\Contract\PermissionDef;
use Tds\Frontend\Contract\SettingDef;
use Tds\Frontend\Contract\SettingsStore;
use Tds\Frontend\Contract\UserContext;

/**
 * Backend Module for the Lexware billing hub. Connects the panel's data to
 * Lexware Office: a lightweight customer/project directory, time→invoice export
 * (aggregating tds-ext-time-tracker entries linked to a project), and contact/
 * lead push (from the directory and — defensively, when present — the ticket
 * systems).
 *
 * Auth comes entirely from the core {@see UserContext}: reads require
 * `lexware:read`, mutations `lexware:write` (admins bypass). Config (API key,
 * URL, default rates) lives in the core {@see SettingsStore} under ns=`lexware`,
 * DB-first with an env fallback. Data via the core shared PDO.
 */
final class LexwareModule extends AbstractModule
{
    private const NS = 'lexware';

    public function id(): string
    {
        return 'lexware';
    }

    /** @return PermissionDef[] */
    public function permissions(): array
    {
        return [
            new PermissionDef('lexware:read', 'Lexware / Rechnungen ansehen', 'lexware'),
            new PermissionDef('lexware:write', 'Lexware-Kunden, Kontakte & Rechnungen verwalten', 'lexware'),
        ];
    }

    /** @return string[] */
    public function migrations(): array
    {
        return [__DIR__ . '/../db/migrations'];
    }

    /** @return SettingDef[] */
    public function settings(): array
    {
        return [
            new SettingDef('api_key', 'Lexware API-Key', true, 'lexware'),
            new SettingDef('api_url', 'Lexware API-URL', false, 'lexware', 'https://api.lexware.io/v1'),
            new SettingDef('default_hourly_rate', 'Standard-Stundensatz (netto)', false, 'lexware', '0'),
            new SettingDef('default_tax_rate', 'Standard-Steuersatz (%)', false, 'lexware', '19'),
        ];
    }

    public function register(App $app): void
    {
        $c = $app->getContainer();
        if ($c !== null && !$c->has(CustomerRepository::class)) {
            $c->set(CustomerRepository::class, static fn ($c) => new CustomerRepository($c->get(PDO::class)));
            $c->set(TimeLinkRepository::class, static fn ($c) => new TimeLinkRepository($c->get(PDO::class)));
            $c->set(ContactMapRepository::class, static fn ($c) => new ContactMapRepository($c->get(PDO::class)));
            $c->set(InvoiceLogRepository::class, static fn ($c) => new InvoiceLogRepository($c->get(PDO::class)));
            $c->set(SourceGateway::class, static fn ($c) => new SourceGateway($c->get(PDO::class)));
            $c->set(LexwareInvoiceBuilder::class, static fn () => new LexwareInvoiceBuilder());
            $c->set(LexwareContactBuilder::class, static fn () => new LexwareContactBuilder());
            $c->set(LexwareClient::class, static function ($c): LexwareClient {
                $store = self::store($c);
                $key = $store?->getSecret(self::NS, 'api_key');
                if ($key === null || $key === '') {
                    $key = self::env('LEXWARE_API_KEY', '');
                }
                $url = $store?->get(self::NS, 'api_url');
                if ($url === null || $url === '') {
                    $url = self::env('LEXWARE_API_URL', 'https://api.lexware.io/v1');
                }
                return new LexwareClient($key, $url);
            });
        }

        // --- Dashboard widget + overview --------------------------------------
        $app->get('/lexware/summary', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'lexware:read', $res)) !== null) {
                return $deny;
            }
            $log = $c->get(InvoiceLogRepository::class);
            return self::json($res, [
                'configured' => $c->get(LexwareClient::class)->isConfigured(),
                'invoiceCount' => $log->count(),
                'recent' => $log->recent(5),
            ]);
        });

        // --- Customer/project directory ---------------------------------------
        $app->get('/lexware/customers', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'lexware:read', $res)) !== null) {
                return $deny;
            }
            return self::json($res, ['customers' => $c->get(CustomerRepository::class)->listCustomers()]);
        });

        $app->post('/lexware/customers', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'lexware:write', $res)) !== null) {
                return $deny;
            }
            $body = (array) $req->getParsedBody();
            $name = trim((string) ($body['name'] ?? ''));
            if ($name === '') {
                return self::json($res, ['error' => 'name is required'], 422);
            }
            $id = $c->get(CustomerRepository::class)->createCustomer([
                'name' => $name,
                'email' => self::optionalEmail($body['email'] ?? null),
                'default_hourly_rate' => self::num($body['default_hourly_rate'] ?? null),
                'tax_rate_percent' => self::num($body['tax_rate_percent'] ?? null),
                'note' => self::optional($body['note'] ?? null, 2000),
            ]);
            return self::json($res, ['id' => $id], 201);
        });

        $app->get('/lexware/customers/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'lexware:read', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(CustomerRepository::class);
            $customer = $repo->findCustomer((int) $args['id']);
            if ($customer === null) {
                return self::json($res, ['error' => 'Not found'], 404);
            }
            $customer['projects'] = $repo->projects((int) $args['id']);
            return self::json($res, $customer);
        });

        $app->patch('/lexware/customers/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'lexware:write', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(CustomerRepository::class);
            $id = (int) $args['id'];
            $existing = $repo->findCustomer($id);
            if ($existing === null) {
                return self::json($res, ['error' => 'Not found'], 404);
            }
            $body = (array) $req->getParsedBody();
            $name = trim((string) ($body['name'] ?? $existing['name']));
            if ($name === '') {
                return self::json($res, ['error' => 'name is required'], 422);
            }
            $repo->updateCustomer($id, [
                'name' => $name,
                'email' => array_key_exists('email', $body) ? self::optionalEmail($body['email']) : $existing['email'],
                'default_hourly_rate' => array_key_exists('default_hourly_rate', $body) ? self::num($body['default_hourly_rate']) : $existing['default_hourly_rate'],
                'tax_rate_percent' => array_key_exists('tax_rate_percent', $body) ? self::num($body['tax_rate_percent']) : $existing['tax_rate_percent'],
                'note' => array_key_exists('note', $body) ? self::optional($body['note'], 2000) : $existing['note'],
            ]);
            return self::json($res, ['ok' => true]);
        });

        $app->delete('/lexware/customers/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'lexware:write', $res)) !== null) {
                return $deny;
            }
            $c->get(CustomerRepository::class)->deleteCustomer((int) $args['id']);
            return self::json($res, ['ok' => true]);
        });

        $app->post('/lexware/customers/{id:[0-9]+}/projects', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'lexware:write', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(CustomerRepository::class);
            $id = (int) $args['id'];
            if ($repo->findCustomer($id) === null) {
                return self::json($res, ['error' => 'Not found'], 404);
            }
            $body = (array) $req->getParsedBody();
            $title = trim((string) ($body['title'] ?? ''));
            if ($title === '') {
                return self::json($res, ['error' => 'title is required'], 422);
            }
            $pid = $repo->createProject(
                $id,
                $title,
                self::num($body['hourly_rate'] ?? null),
                self::enum($body['status'] ?? 'active', ['active', 'archived'], 'active'),
            );
            return self::json($res, ['id' => $pid], 201);
        });

        // --- Time-entry → project assignment ----------------------------------
        $app->get('/lexware/time/unassigned', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'lexware:read', $res)) !== null) {
                return $deny;
            }
            $q = $req->getQueryParams();
            $entries = $c->get(SourceGateway::class)->unassignedTimeEntries(
                self::optional($q['from'] ?? null, 10),
                self::optional($q['to'] ?? null, 10),
            );
            return self::json($res, ['entries' => $entries]);
        });

        $app->post('/lexware/time/assign', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'lexware:write', $res)) !== null) {
                return $deny;
            }
            $body = (array) $req->getParsedBody();
            $entryId = (int) ($body['timeEntryId'] ?? 0);
            $projectId = (int) ($body['projectId'] ?? 0);
            if ($entryId <= 0 || $projectId <= 0) {
                return self::json($res, ['error' => 'timeEntryId and projectId are required'], 422);
            }
            if ($c->get(CustomerRepository::class)->findProjectWithCustomer($projectId) === null) {
                return self::json($res, ['error' => 'Unknown project'], 404);
            }
            $c->get(TimeLinkRepository::class)->assign($entryId, $projectId);
            return self::json($res, ['ok' => true]);
        });

        $app->post('/lexware/time/unassign', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'lexware:write', $res)) !== null) {
                return $deny;
            }
            $entryId = (int) (((array) $req->getParsedBody())['timeEntryId'] ?? 0);
            if ($entryId <= 0) {
                return self::json($res, ['error' => 'timeEntryId is required'], 422);
            }
            $c->get(TimeLinkRepository::class)->unassign($entryId);
            return self::json($res, ['ok' => true]);
        });

        // --- Contact / lead push ----------------------------------------------
        $app->get('/lexware/leads', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'lexware:read', $res)) !== null) {
                return $deny;
            }
            $leads = $c->get(SourceGateway::class)->leadCandidates();
            $map = $c->get(ContactMapRepository::class);
            $contactMap = $map->allForType('contact_message');
            $ticketMap = $map->allForType('ticket');
            foreach ($leads as &$lead) {
                $lookup = $lead['source_type'] === 'ticket' ? $ticketMap : $contactMap;
                $lead['lexware_contact_id'] = $lookup[$lead['source_id']] ?? null;
            }
            unset($lead);
            return self::json($res, ['leads' => $leads]);
        });

        // Push a directory customer to Lexware as a contact.
        $app->post('/lexware/customers/{id:[0-9]+}/push-contact', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'lexware:write', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(CustomerRepository::class);
            $customer = $repo->findCustomer((int) $args['id']);
            if ($customer === null) {
                return self::json($res, ['error' => 'Not found'], 404);
            }
            $client = $c->get(LexwareClient::class);
            if (!$client->isConfigured()) {
                return self::json($res, ['error' => 'Lexware API-Key nicht konfiguriert'], 503);
            }
            $payload = $c->get(LexwareContactBuilder::class)->build(
                $customer['name'],
                $customer['email'],
                null,
            );
            try {
                $result = $client->createContact($payload);
            } catch (LexwareException $e) {
                return self::json($res, ['error' => $e->getMessage()], 502);
            }
            $contactId = (string) $result['id'];
            $repo->setLexwareContactId((int) $args['id'], $contactId);
            $c->get(ContactMapRepository::class)->record('lx_customer', (int) $args['id'], $contactId);
            return self::json($res, ['lexwareContactId' => $contactId], 201);
        });

        // Push a lead (from the ticket systems) to Lexware as a contact.
        $app->post('/lexware/leads/push', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'lexware:write', $res)) !== null) {
                return $deny;
            }
            $body = (array) $req->getParsedBody();
            $sourceType = self::enum($body['source_type'] ?? '', ['contact_message', 'ticket'], '');
            $sourceId = (int) ($body['source_id'] ?? 0);
            $name = trim((string) ($body['name'] ?? ''));
            $email = self::optionalEmail($body['email'] ?? null);
            $company = self::optional($body['company'] ?? null, 250);
            if ($sourceType === '' || $sourceId <= 0 || ($name === '' && $email === null)) {
                return self::json($res, ['error' => 'source_type, source_id and a name or email are required'], 422);
            }
            $client = $c->get(LexwareClient::class);
            if (!$client->isConfigured()) {
                return self::json($res, ['error' => 'Lexware API-Key nicht konfiguriert'], 503);
            }
            $payload = $c->get(LexwareContactBuilder::class)->build($name, $email, $company);
            try {
                $result = $client->createContact($payload);
            } catch (LexwareException $e) {
                return self::json($res, ['error' => $e->getMessage()], 502);
            }
            $contactId = (string) $result['id'];
            $c->get(ContactMapRepository::class)->record($sourceType, $sourceId, $contactId);
            return self::json($res, ['lexwareContactId' => $contactId], 201);
        });

        // --- Invoice export ---------------------------------------------------
        $app->post('/lexware/invoices/from-project', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'lexware:write', $res)) !== null) {
                return $deny;
            }
            $body = (array) $req->getParsedBody();
            $projectId = (int) ($body['projectId'] ?? 0);
            $project = $c->get(CustomerRepository::class)->findProjectWithCustomer($projectId);
            if ($project === null) {
                return self::json($res, ['error' => 'Unknown project'], 404);
            }
            $client = $c->get(LexwareClient::class);
            if (!$client->isConfigured()) {
                return self::json($res, ['error' => 'Lexware API-Key nicht konfiguriert'], 503);
            }
            $from = self::optional($body['from'] ?? null, 10);
            $to = self::optional($body['to'] ?? null, 10);
            $finalize = (bool) ($body['finalize'] ?? false);

            // Effective net rate: request override → project → customer → global default.
            $rate = self::num($body['hourlyRate'] ?? null)
                ?? $project['hourly_rate']
                ?? $project['default_hourly_rate']
                ?? (float) self::globalDefault($c, 'default_hourly_rate', 'LEXWARE_DEFAULT_HOURLY_RATE', '0');
            if ($rate <= 0) {
                return self::json($res, ['error' => 'Kein Stundensatz für dieses Projekt/diesen Kunden hinterlegt.'], 422);
            }
            $tax = self::num($body['taxRatePercentage'] ?? null)
                ?? $project['tax_rate_percent']
                ?? (float) self::globalDefault($c, 'default_tax_rate', 'LEXWARE_TAX_RATE_PERCENT', '19');

            $entries = $c->get(TimeLinkRepository::class)->billableForProject($projectId, $from, $to);
            if ($entries === []) {
                return self::json($res, ['error' => 'Keine abrechenbaren Zeiteinträge im Zeitraum.'], 422);
            }

            $built = $c->get(LexwareInvoiceBuilder::class)->build(
                $entries,
                $project['customer_name'],
                $project['lexware_contact_id'],
                $project['title'],
                $rate,
                $tax,
                new DateTimeImmutable('now'),
            );
            try {
                $result = $client->createInvoice($built['payload'], $finalize);
            } catch (LexwareException $e) {
                return self::json($res, ['error' => $e->getMessage()], 502);
            }

            $c->get(InvoiceLogRepository::class)->log([
                'lexware_invoice_id' => (string) $result['id'],
                'resource_uri' => isset($result['resourceUri']) ? (string) $result['resourceUri'] : null,
                'customer_id' => $project['customer_id'],
                'project_id' => $projectId,
                'period_from' => $from,
                'period_to' => $to,
                'total_minutes' => $built['totalMinutes'],
                'line_item_count' => $built['lineItemCount'],
                'finalized' => $finalize,
            ]);
            return self::json($res, [
                'id' => (string) $result['id'],
                'resourceUri' => $result['resourceUri'] ?? null,
                'totalMinutes' => $built['totalMinutes'],
                'lineItemCount' => $built['lineItemCount'],
                'finalized' => $finalize,
            ], 201);
        });

        $app->get('/lexware/invoices', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'lexware:read', $res)) !== null) {
                return $deny;
            }
            return self::json($res, ['invoices' => $c->get(InvoiceLogRepository::class)->recent(50)]);
        });

        // --- Connection test (admin diagnostic) -------------------------------
        $app->get('/lexware/admin/test', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $client = $c->get(LexwareClient::class);
            if (!$client->isConfigured()) {
                return self::json($res, ['ok' => false, 'error' => 'Lexware API-Key nicht konfiguriert'], 503);
            }
            try {
                $profile = $client->ping();
            } catch (LexwareException $e) {
                return self::json($res, ['ok' => false, 'error' => $e->getMessage()]);
            }
            return self::json($res, ['ok' => true, 'organizationId' => $profile['organizationId'] ?? null]);
        });
    }

    // --- helpers ---------------------------------------------------------------

    /** 401/403 response when the principal fails the permission check, else null. */
    private static function require(UserContext $user, string $permission, Response $res): ?Response
    {
        if (!$user->isAuthenticated()) {
            return self::json($res, ['error' => 'Unauthorized'], 401);
        }
        if (!$user->has($permission)) {
            return self::json($res, ['error' => 'Forbidden'], 403);
        }
        return null;
    }

    private static function requireAdmin(UserContext $user, Response $res): ?Response
    {
        if (!$user->isAuthenticated()) {
            return self::json($res, ['error' => 'Unauthorized'], 401);
        }
        if (!$user->isAdmin()) {
            return self::json($res, ['error' => 'Forbidden'], 403);
        }
        return null;
    }

    /**
     * The core settings store if the base bound it (it resolves the contract
     * interface), else null — so an isolated unit test (no core) falls back to env.
     */
    private static function store(ContainerInterface $c): ?SettingsStore
    {
        return $c->has(SettingsStore::class) ? $c->get(SettingsStore::class) : null;
    }

    /** A non-secret global default: settings store (DB-first) → env → coded default. */
    private static function globalDefault(ContainerInterface $c, string $key, string $envKey, string $default): string
    {
        $v = self::store($c)?->get(self::NS, $key);
        if ($v !== null && $v !== '') {
            return $v;
        }
        return self::env($envKey, $default);
    }

    /**
     * Read an env var with an explicit default. Avoids the `?? getenv() ?: $d`
     * precedence trap — a legitimately falsy value ("0") must not be clobbered.
     */
    private static function env(string $key, string $default): string
    {
        $v = getenv($key);
        return $v === false ? $default : $v;
    }

    /** Parse a nullable numeric field to float, or null when absent/blank. */
    private static function num(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }

    private static function optional(mixed $value, int $limit): ?string
    {
        $v = trim((string) ($value ?? ''));
        return $v === '' ? null : mb_substr($v, 0, $limit);
    }

    /** Validate an optional email; null when blank or invalid. */
    private static function optionalEmail(mixed $value): ?string
    {
        $v = strtolower(trim((string) ($value ?? '')));
        return $v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL) !== false ? $v : null;
    }

    /** @param string[] $allowed */
    private static function enum(mixed $value, array $allowed, string $default): string
    {
        $v = is_string($value) ? $value : '';
        return in_array($v, $allowed, true) ? $v : $default;
    }

    private static function json(Response $res, mixed $data, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $res->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
