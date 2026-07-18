<?php
declare(strict_types=1);

namespace Tds\Ext\Lexware\Service;

/**
 * Builds a Lexware Office `POST /contacts` payload from a name/email/company
 * triple (a directory customer, or a lead harvested from the ticket systems).
 * A non-empty company name creates a company contact; otherwise a person
 * contact (the name split into first/last). Pure + stateless → unit-testable.
 *
 * @see https://developers.lexware.io/docs/#contacts-endpoint-purpose
 */
final class LexwareContactBuilder
{
    /**
     * @return array<string,mixed>
     */
    public function build(string $name, ?string $email, ?string $company): array
    {
        $company = $company !== null ? trim($company) : '';
        $name = trim($name);

        // `version: 0` marks a create (Lexware uses optimistic locking).
        $payload = [
            'version' => 0,
            'roles' => ['customer' => (object) []],
        ];

        if ($company !== '') {
            $payload['company'] = ['name' => mb_substr($company, 0, 250)];
            if ($name !== '') {
                $payload['company']['contactPersons'] = [
                    array_filter([
                        'lastName' => mb_substr($name, 0, 250),
                        'primary' => true,
                        'emailAddress' => $email !== null && $email !== '' ? $email : null,
                    ], static fn ($v): bool => $v !== null),
                ];
            }
        } else {
            [$first, $last] = self::splitName($name);
            $payload['person'] = array_filter([
                'firstName' => $first,
                'lastName' => $last !== '' ? $last : 'Unbekannt',
            ], static fn ($v): bool => $v !== '');
        }

        $mail = $email !== null ? trim($email) : '';
        if ($mail !== '') {
            $payload['emailAddresses'] = ['business' => [$mail]];
        }

        return $payload;
    }

    /**
     * Split a display name into [firstName, lastName]. A single token becomes
     * the last name (Lexware requires a last name on a person contact).
     *
     * @return array{0:string,1:string}
     */
    private static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($parts) <= 1) {
            return ['', mb_substr($parts[0] ?? '', 0, 250)];
        }
        $last = array_pop($parts);
        return [mb_substr(implode(' ', $parts), 0, 250), mb_substr((string) $last, 0, 250)];
    }
}
