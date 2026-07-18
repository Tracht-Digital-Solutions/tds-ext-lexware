<?php
declare(strict_types=1);

namespace Tds\Ext\Lexware\Tests;

use PHPUnit\Framework\TestCase;
use Tds\Ext\Lexware\Service\LexwareContactBuilder;

final class LexwareContactBuilderTest extends TestCase
{
    public function testCompanyContact(): void
    {
        $p = (new LexwareContactBuilder())->build('Max Mustermann', 'max@acme.de', 'ACME GmbH');
        self::assertSame(0, $p['version']);
        self::assertArrayHasKey('customer', $p['roles']);
        self::assertSame('ACME GmbH', $p['company']['name']);
        self::assertSame('Max Mustermann', $p['company']['contactPersons'][0]['lastName']);
        self::assertSame(['business' => ['max@acme.de']], $p['emailAddresses']);
        self::assertArrayNotHasKey('person', $p);
    }

    public function testPersonContactSplitsName(): void
    {
        $p = (new LexwareContactBuilder())->build('Erika Musterfrau', 'erika@example.com', null);
        self::assertSame('Erika', $p['person']['firstName']);
        self::assertSame('Musterfrau', $p['person']['lastName']);
        self::assertArrayNotHasKey('company', $p);
    }

    public function testSingleNameBecomesLastName(): void
    {
        $p = (new LexwareContactBuilder())->build('Cher', null, null);
        self::assertSame('Cher', $p['person']['lastName']);
        self::assertArrayNotHasKey('firstName', $p['person']);
        self::assertArrayNotHasKey('emailAddresses', $p);
    }

    public function testEmptyNameGetsPlaceholderLastName(): void
    {
        $p = (new LexwareContactBuilder())->build('', 'x@y.de', null);
        self::assertSame('Unbekannt', $p['person']['lastName']);
    }
}
