<?php
declare(strict_types=1);

namespace Tds\Ext\Lexware\Service;

use PDO;

/**
 * Defensive read access to OTHER extensions' tables over the shared PDO
 * connection (in-process, one DB). Lexware integrates with the time-tracker and
 * the two ticket systems, but declares NO hard `dependsOn` on them — so every
 * cross-extension read is guarded by an existence check and returns `[]` when
 * the source extension isn't composed into this build. That keeps Lexware
 * composable on its own.
 */
final class SourceGateway
{
    /** @var array<string,bool> memoised table-existence checks */
    private array $exists = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Completed time-tracker entries not yet linked to a Lexware project, for
     * the assignment UI. `[]` when tds-ext-time-tracker isn't present.
     *
     * @return list<array<string,mixed>>
     */
    public function unassignedTimeEntries(?string $from, ?string $to): array
    {
        if (!$this->tableExists('time_entry')) {
            return [];
        }
        $sql = 'SELECT te.id, te.started_at, te.ended_at, te.note,
                       TIMESTAMPDIFF(MINUTE, te.started_at, te.ended_at) AS duration_minutes
                FROM time_entry te
                LEFT JOIN lx_time_link l ON l.time_entry_id = te.id
                WHERE te.ended_at IS NOT NULL AND l.time_entry_id IS NULL';
        $params = [];
        if ($from !== null && $from !== '') {
            $sql .= ' AND te.started_at >= :from';
            $params[':from'] = $from . ' 00:00:00';
        }
        if ($to !== null && $to !== '') {
            $sql .= ' AND te.started_at <= :to';
            $params[':to'] = $to . ' 23:59:59';
        }
        $sql .= ' ORDER BY te.started_at DESC LIMIT 500';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'started_at' => (string) $r['started_at'],
            'ended_at' => (string) $r['ended_at'],
            'note' => $r['note'] !== null ? (string) $r['note'] : null,
            'duration_minutes' => (int) $r['duration_minutes'],
        ], $stmt->fetchAll());
    }

    /**
     * Contact/lead candidates harvested from the ticket systems — a real
     * person's name + email (+ company) each, deduped by lowercased email
     * (contact-tickets wins over support-tickets). `[]` when neither source is
     * present. Each row: source_type, source_id, name, email, company.
     *
     * @return list<array<string,mixed>>
     */
    public function leadCandidates(): array
    {
        $byEmail = [];

        if ($this->tableExists('contact_message')) {
            $rows = $this->pdo->query(
                "SELECT id, name, email, company FROM contact_message
                 WHERE email <> '' ORDER BY created_at DESC LIMIT 500"
            )->fetchAll();
            foreach ($rows as $r) {
                $email = strtolower(trim((string) $r['email']));
                if ($email === '' || isset($byEmail[$email])) {
                    continue;
                }
                $byEmail[$email] = [
                    'source_type' => 'contact_message',
                    'source_id' => (int) $r['id'],
                    'name' => (string) $r['name'],
                    'email' => (string) $r['email'],
                    'company' => $r['company'] !== null ? (string) $r['company'] : null,
                ];
            }
        }

        if ($this->tableExists('ticket')) {
            // One candidate per distinct sender email that carries contact details.
            $rows = $this->pdo->query(
                "SELECT MIN(id) AS id, from_name, from_email, from_company
                 FROM ticket
                 WHERE from_email IS NOT NULL AND from_email <> ''
                 GROUP BY from_email, from_name, from_company
                 ORDER BY id DESC LIMIT 500"
            )->fetchAll();
            foreach ($rows as $r) {
                $email = strtolower(trim((string) $r['from_email']));
                if ($email === '' || isset($byEmail[$email])) {
                    continue;
                }
                $byEmail[$email] = [
                    'source_type' => 'ticket',
                    'source_id' => (int) $r['id'],
                    'name' => (string) ($r['from_name'] ?? ''),
                    'email' => (string) $r['from_email'],
                    'company' => $r['from_company'] !== null ? (string) $r['from_company'] : null,
                ];
            }
        }

        return array_values($byEmail);
    }

    /** Memoised check whether a table exists in the current database. */
    private function tableExists(string $table): bool
    {
        if (isset($this->exists[$table])) {
            return $this->exists[$table];
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :t'
        );
        $stmt->execute([':t' => $table]);
        return $this->exists[$table] = ((int) $stmt->fetchColumn() > 0);
    }
}
