<?php
declare(strict_types=1);

namespace Tds\Ext\Lexware\Domain;

use PDO;

/**
 * Dedupe map between a source record (a directory customer, a contact-form
 * message or a ticket sender) and the Lexware contact it was pushed to
 * (`lx_contact_map`). Lets the UI mark already-synced candidates and avoid
 * creating duplicate Lexware contacts.
 */
final class ContactMapRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** The Lexware contact id a source was mapped to, or null. */
    public function mappedId(string $sourceType, int $sourceId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT lexware_contact_id FROM lx_contact_map
             WHERE source_type = :t AND source_id = :i'
        );
        $stmt->execute([':t' => $sourceType, ':i' => $sourceId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public function record(string $sourceType, int $sourceId, string $lexwareContactId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO lx_contact_map (source_type, source_id, lexware_contact_id)
             VALUES (:t, :i, :cid)
             ON DUPLICATE KEY UPDATE lexware_contact_id = VALUES(lexware_contact_id), synced_at = NOW()'
        );
        $stmt->execute([':t' => $sourceType, ':i' => $sourceId, ':cid' => $lexwareContactId]);
    }

    /**
     * All mappings for a source type, keyed by source id → contact id (so the
     * lead list can be annotated in one query).
     *
     * @return array<int,string>
     */
    public function allForType(string $sourceType): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT source_id, lexware_contact_id FROM lx_contact_map WHERE source_type = :t'
        );
        $stmt->execute([':t' => $sourceType]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['source_id']] = (string) $r['lexware_contact_id'];
        }
        return $out;
    }
}
