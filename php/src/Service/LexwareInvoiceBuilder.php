<?php
declare(strict_types=1);

namespace Tds\Ext\Lexware\Service;

use DateTimeInterface;

/**
 * Turns a project's billable time entries into a Lexware invoice payload.
 *
 * Ported from tds-customer-api's builder, adapted for the extension world: the
 * time-tracker has no milestones, so entries are grouped by their `note`
 * (entries without one fall under the project title) — one service line per
 * label, quantity = hours, priced at the given net hourly rate. When the
 * customer already has a Lexware contact the invoice references it via
 * `address.contactId`; otherwise it carries a free-text `address.name`. Pure +
 * stateless so the aggregation is unit-testable without the HTTP client.
 */
final class LexwareInvoiceBuilder
{
    /**
     * @param array<int,array<string,mixed>> $entries rows with `duration_minutes` + `note` (nullable)
     * @return array{payload:array<string,mixed>,totalMinutes:int,lineItemCount:int}
     */
    public function build(
        array $entries,
        string $customerName,
        ?string $lexwareContactId,
        string $projectTitle,
        float $hourlyRateNet,
        float $taxRatePercentage,
        DateTimeInterface $voucherDate,
        string $currency = 'EUR',
    ): array {
        // Sum minutes per label, preserving first-seen order.
        $minutesByLabel = [];
        $totalMinutes = 0;
        foreach ($entries as $e) {
            $minutes = (int) ($e['duration_minutes'] ?? 0);
            if ($minutes <= 0) {
                continue;
            }
            $label = isset($e['note']) && is_string($e['note']) && trim($e['note']) !== ''
                ? trim($e['note'])
                : $projectTitle;
            $minutesByLabel[$label] = ($minutesByLabel[$label] ?? 0) + $minutes;
            $totalMinutes += $minutes;
        }

        $rate = round($hourlyRateNet, 2);
        $lineItems = [];
        foreach ($minutesByLabel as $label => $minutes) {
            $hours = round($minutes / 60, 2);
            $lineItems[] = [
                'type' => 'service',
                'name' => $projectTitle,
                'description' => $label,
                'quantity' => $hours,
                'unitName' => 'Stunde',
                'unitPrice' => [
                    'currency' => $currency,
                    'netAmount' => $rate,
                    'taxRatePercentage' => $taxRatePercentage,
                ],
            ];
        }

        $address = $lexwareContactId !== null && $lexwareContactId !== ''
            ? ['contactId' => $lexwareContactId]
            : ['name' => $customerName, 'countryCode' => 'DE'];

        $payload = [
            'voucherDate' => $voucherDate->format('Y-m-d\TH:i:s.000P'),
            'address' => $address,
            'lineItems' => $lineItems,
            'totalPrice' => ['currency' => $currency],
            'taxConditions' => ['taxType' => 'net'],
            'shippingConditions' => [
                'shippingType' => 'service',
                'shippingDate' => $voucherDate->format('Y-m-d\TH:i:s.000P'),
            ],
            'title' => 'Rechnung',
            'introduction' => sprintf(
                'Erfasste Leistungen für %s (%s Stunden).',
                $projectTitle,
                number_format($totalMinutes / 60, 2, ',', '.'),
            ),
        ];

        return [
            'payload' => $payload,
            'totalMinutes' => $totalMinutes,
            'lineItemCount' => count($lineItems),
        ];
    }
}
