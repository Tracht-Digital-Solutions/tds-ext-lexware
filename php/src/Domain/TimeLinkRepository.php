<?php
declare(strict_types=1);

namespace Tds\Ext\Lexware\Domain;

use PDO;

/**
 * Links tds-ext-time-tracker `time_entry` rows to a Lexware project (`lx_time_link`)
 * and reads the billable time for a project. The time-tracker table lives in the
 * same DB (shared PDO) but is another extension's, so there is no cross-domain
 * FK — one link per entry is enforced by a unique index.
 */
final class TimeLinkRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Assign an entry to a project (idempotent — re-assign moves the link). */
    public function assign(int $timeEntryId, int $projectId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO lx_time_link (time_entry_id, project_id) VALUES (:e, :p)
             ON DUPLICATE KEY UPDATE project_id = VALUES(project_id)'
        );
        $stmt->execute([':e' => $timeEntryId, ':p' => $projectId]);
    }

    public function unassign(int $timeEntryId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM lx_time_link WHERE time_entry_id = :e');
        $stmt->execute([':e' => $timeEntryId]);
    }

    /**
     * Completed, project-linked time entries in an optional date window, with a
     * computed `duration_minutes` (the time_entry table stores no duration).
     * Empty when the time-tracker table is absent — the JOIN simply matches
     * nothing since no link rows can exist without it.
     *
     * @return list<array<string,mixed>>
     */
    public function billableForProject(int $projectId, ?string $from, ?string $to): array
    {
        $sql = 'SELECT te.id, te.note,
                       TIMESTAMPDIFF(MINUTE, te.started_at, te.ended_at) AS duration_minutes
                FROM lx_time_link l
                INNER JOIN time_entry te ON te.id = l.time_entry_id
                WHERE l.project_id = :pid AND te.ended_at IS NOT NULL';
        $params = [':pid' => $projectId];
        if ($from !== null && $from !== '') {
            $sql .= ' AND te.started_at >= :from';
            $params[':from'] = $from . ' 00:00:00';
        }
        if ($to !== null && $to !== '') {
            $sql .= ' AND te.started_at <= :to';
            $params[':to'] = $to . ' 23:59:59';
        }
        $sql .= ' ORDER BY te.started_at ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'note' => $r['note'] !== null ? (string) $r['note'] : null,
            'duration_minutes' => (int) $r['duration_minutes'],
        ], $stmt->fetchAll());
    }
}
