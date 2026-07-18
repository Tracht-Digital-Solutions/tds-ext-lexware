<?php
declare(strict_types=1);

namespace Tds\Ext\Lexware\Domain;

use PDO;

/**
 * Audit log of invoices created in Lexware (`lx_invoice_log`) — one row per
 * successful export. Backs the "Rechnungen" list and the dashboard widget
 * (recent + count).
 */
final class InvoiceLogRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{lexware_invoice_id:string,resource_uri:?string,customer_id:?int,project_id:?int,period_from:?string,period_to:?string,total_minutes:int,line_item_count:int,finalized:bool} $d
     */
    public function log(array $d): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO lx_invoice_log
                (lexware_invoice_id, resource_uri, customer_id, project_id, period_from,
                 period_to, total_minutes, line_item_count, finalized)
             VALUES (:iid, :uri, :cid, :pid, :from, :to, :mins, :lines, :fin)'
        );
        $stmt->execute([
            ':iid' => $d['lexware_invoice_id'],
            ':uri' => $d['resource_uri'],
            ':cid' => $d['customer_id'],
            ':pid' => $d['project_id'],
            ':from' => $d['period_from'],
            ':to' => $d['period_to'],
            ':mins' => $d['total_minutes'],
            ':lines' => $d['line_item_count'],
            ':fin' => $d['finalized'] ? 1 : 0,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->pdo->query(
            "SELECT g.id, g.lexware_invoice_id, g.resource_uri, g.customer_id, g.project_id,
                    g.period_from, g.period_to, g.total_minutes, g.line_item_count, g.finalized,
                    g.created_at, c.name AS customer_name
             FROM lx_invoice_log g
             LEFT JOIN lx_customer c ON c.id = g.customer_id
             ORDER BY g.created_at DESC LIMIT {$limit}"
        )->fetchAll();
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'lexware_invoice_id' => (string) $r['lexware_invoice_id'],
            'resource_uri' => $r['resource_uri'] !== null ? (string) $r['resource_uri'] : null,
            'customer_id' => $r['customer_id'] !== null ? (int) $r['customer_id'] : null,
            'customer_name' => $r['customer_name'] !== null ? (string) $r['customer_name'] : null,
            'project_id' => $r['project_id'] !== null ? (int) $r['project_id'] : null,
            'period_from' => $r['period_from'] !== null ? (string) $r['period_from'] : null,
            'period_to' => $r['period_to'] !== null ? (string) $r['period_to'] : null,
            'total_minutes' => (int) $r['total_minutes'],
            'line_item_count' => (int) $r['line_item_count'],
            'finalized' => (int) $r['finalized'] === 1,
            'created_at' => (string) $r['created_at'],
        ], $rows);
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM lx_invoice_log')->fetchColumn();
    }
}
