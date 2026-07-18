<?php
declare(strict_types=1);

namespace Tds\Ext\Lexware\Service;

/**
 * Thin client for the Lexware Office (formerly lexoffice) public REST API.
 * Auth is the API key as a Bearer token (created in the Lexware Office
 * settings > öffentliche API); the base URL defaults to the production host and
 * is overridable for the sandbox (https://api.lexware-sandbox.de/v1).
 *
 * Ported from tds-customer-api's LexwareClient to plain ext-curl (no Guzzle) —
 * the extension convention, so the extension has no HTTP-client dependency.
 *
 * @see https://developers.lexware.io/docs/
 */
final class LexwareClient
{
    public function __construct(
        private readonly string $apiKey,
        /** e.g. https://api.lexware.io/v1 (sandbox: https://api.lexware-sandbox.de/v1). */
        private readonly string $baseUrl = 'https://api.lexware.io/v1',
    ) {
    }

    /** False when no API key is configured — the feature is then disabled. */
    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Create an invoice. With $finalize the invoice is created in `open` status
     * (a real, numbered invoice); otherwise it stays a `draft`.
     *
     * @param array<string,mixed> $payload the Lexware invoice JSON body
     * @return array<string,mixed> decoded response (id, resourceUri, …)
     * @throws LexwareException on a non-201 response or transport error
     */
    public function createInvoice(array $payload, bool $finalize = false): array
    {
        $path = '/invoices' . ($finalize ? '?finalize=true' : '');
        return $this->created($this->request('POST', $path, $payload), 'Rechnung');
    }

    /**
     * Create a contact (customer role). Returns the decoded response incl. the
     * new contact `id` (used as `address.contactId` on later invoices).
     *
     * @param array<string,mixed> $payload the Lexware contact JSON body
     * @return array<string,mixed>
     * @throws LexwareException
     */
    public function createContact(array $payload): array
    {
        return $this->created($this->request('POST', '/contacts', $payload), 'Kontakt');
    }

    /**
     * Connection test — reads the authenticated profile. Returns the decoded
     * body on 200, throws otherwise (so the admin route surfaces the reason).
     *
     * @return array<string,mixed>
     * @throws LexwareException
     */
    public function ping(): array
    {
        [$status, $body] = $this->request('GET', '/profile', null);
        if ($status !== 200) {
            throw new LexwareException(self::errorMessage($status, $body), $status);
        }
        return is_array($body) ? $body : [];
    }

    /**
     * Validate a create response: Lexware's create endpoints return 201 with an
     * `id`. Returns the decoded body or throws with a readable message.
     *
     * @param array{0:int,1:array<string,mixed>|null} $result
     * @return array<string,mixed>
     */
    private function created(array $result, string $what): array
    {
        [$status, $body] = $result;
        if ($status !== 201) {
            throw new LexwareException(self::errorMessage($status, $body), $status);
        }
        if (!is_array($body) || !isset($body['id'])) {
            throw new LexwareException("Unerwartete Lexware-Antwort (keine {$what}-ID).", $status);
        }
        return $body;
    }

    /**
     * Perform a request. Returns [httpStatus, decodedBody|null]. Throws only on
     * a transport error (curl failure) — HTTP error statuses are returned so the
     * caller can map them.
     *
     * @param array<string,mixed>|null $payload JSON body for POST, null for GET
     * @return array{0:int,1:array<string,mixed>|null}
     * @throws LexwareException on a transport error
     */
    private function request(string $method, string $path, ?array $payload): array
    {
        $ch = curl_init($this->baseUrl . $path);
        if ($ch === false) {
            throw new LexwareException('Lexware-Anfrage konnte nicht initialisiert werden.', 0);
        }
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
        ];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new LexwareException('Lexware nicht erreichbar: ' . $err, 0);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $decoded = json_decode((string) $raw, true);
        return [$status, is_array($decoded) ? $decoded : null];
    }

    /**
     * Build a readable message from a Lexware error envelope.
     *
     * @param array<string,mixed>|null $body
     */
    private static function errorMessage(int $status, ?array $body): string
    {
        $detail = '';
        if ($body !== null) {
            if (isset($body['message']) && is_string($body['message'])) {
                $detail = $body['message'];
            } elseif (isset($body['IssueList'][0]['i18nKey']) && is_string($body['IssueList'][0]['i18nKey'])) {
                $detail = $body['IssueList'][0]['i18nKey'];
            }
        }
        $hint = match (true) {
            $status === 401 => 'API-Key ungültig oder abgelaufen',
            $status === 402 => 'Lexware-Abo deckt die API nicht ab',
            $status === 403 => 'API-Key ohne Berechtigung',
            $status === 404 => 'Ressource nicht gefunden',
            $status === 406 => 'Daten von Lexware abgelehnt',
            default => 'HTTP ' . $status,
        };
        return $detail !== '' ? "{$hint}: {$detail}" : $hint;
    }
}
