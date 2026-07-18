<?php
declare(strict_types=1);

namespace Tds\Ext\Lexware\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tds\Ext\Lexware\Service\LexwareInvoiceBuilder;

final class LexwareInvoiceBuilderTest extends TestCase
{
    public function testGroupsByNoteAndSumsHours(): void
    {
        $entries = [
            ['duration_minutes' => 90, 'note' => 'Frontend'],
            ['duration_minutes' => 30, 'note' => 'Frontend'],
            ['duration_minutes' => 60, 'note' => 'Backend'],
            ['duration_minutes' => 0, 'note' => 'Ignored'],   // zero → skipped
        ];
        $built = (new LexwareInvoiceBuilder())->build(
            $entries,
            'ACME GmbH',
            null,
            'Projekt X',
            100.0,
            19.0,
            new DateTimeImmutable('2026-07-19T10:00:00+02:00'),
        );

        self::assertSame(180, $built['totalMinutes']);
        self::assertSame(2, $built['lineItemCount']);
        $items = $built['payload']['lineItems'];
        self::assertSame('Frontend', $items[0]['description']);
        self::assertSame(2.0, $items[0]['quantity']); // 120 min → 2h
        self::assertSame('Stunde', $items[0]['unitName']);
        self::assertSame(100.0, $items[0]['unitPrice']['netAmount']);
        self::assertSame(19.0, $items[0]['unitPrice']['taxRatePercentage']);
        self::assertSame('Backend', $items[1]['description']);
        self::assertSame(1.0, $items[1]['quantity']); // 60 min → 1h
        self::assertSame('net', $built['payload']['taxConditions']['taxType']);
    }

    public function testUsesContactIdWhenPresent(): void
    {
        $built = (new LexwareInvoiceBuilder())->build(
            [['duration_minutes' => 60, 'note' => 'x']],
            'ACME',
            'contact-uuid-123',
            'Projekt',
            80.0,
            19.0,
            new DateTimeImmutable('now'),
        );
        self::assertSame(['contactId' => 'contact-uuid-123'], $built['payload']['address']);
    }

    public function testFallsBackToNameWithoutContactId(): void
    {
        $built = (new LexwareInvoiceBuilder())->build(
            [['duration_minutes' => 60, 'note' => null]],
            'ACME',
            null,
            'Projekt',
            80.0,
            19.0,
            new DateTimeImmutable('now'),
        );
        self::assertSame(['name' => 'ACME', 'countryCode' => 'DE'], $built['payload']['address']);
        // A null note falls under the project title as the line label.
        self::assertSame('Projekt', $built['payload']['lineItems'][0]['description']);
    }
}
