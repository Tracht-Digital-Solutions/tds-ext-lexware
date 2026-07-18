<?php
declare(strict_types=1);

namespace Tds\Ext\Lexware\Domain;

use PDO;

/**
 * The Lexware billing-hub customer/project directory (`lx_customer` +
 * `lx_project`). This is a lightweight directory the extension owns so tracked
 * time can be associated with a customer + rate before it is billed — it is NOT
 * the (future) org-wide customer directory. All access via the core shared PDO.
 */
final class CustomerRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> customers with their project count */
    public function listCustomers(): array
    {
        $rows = $this->pdo->query(
            'SELECT c.id, c.name, c.email, c.lexware_contact_id, c.default_hourly_rate,
                    c.tax_rate_percent, c.note,
                    (SELECT COUNT(*) FROM lx_project p WHERE p.customer_id = c.id) AS project_count
             FROM lx_customer c ORDER BY c.name ASC'
        )->fetchAll();
        return array_map([self::class, 'mapCustomer'], $rows);
    }

    /** @return array<string,mixed>|null */
    public function findCustomer(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, email, lexware_contact_id, default_hourly_rate, tax_rate_percent, note
             FROM lx_customer WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : self::mapCustomer($row);
    }

    /**
     * @param array{name:string,email:?string,default_hourly_rate:?float,tax_rate_percent:?float,note:?string} $d
     */
    public function createCustomer(array $d): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO lx_customer (name, email, default_hourly_rate, tax_rate_percent, note)
             VALUES (:name, :email, :rate, :tax, :note)'
        );
        $stmt->execute([
            ':name' => $d['name'],
            ':email' => $d['email'],
            ':rate' => $d['default_hourly_rate'],
            ':tax' => $d['tax_rate_percent'],
            ':note' => $d['note'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array{name:string,email:?string,default_hourly_rate:?float,tax_rate_percent:?float,note:?string} $d
     */
    public function updateCustomer(int $id, array $d): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE lx_customer SET name = :name, email = :email, default_hourly_rate = :rate,
                    tax_rate_percent = :tax, note = :note WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':name' => $d['name'],
            ':email' => $d['email'],
            ':rate' => $d['default_hourly_rate'],
            ':tax' => $d['tax_rate_percent'],
            ':note' => $d['note'],
        ]);
    }

    public function deleteCustomer(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM lx_customer WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /** Store the Lexware contact id after a successful contact push. */
    public function setLexwareContactId(int $id, string $contactId): void
    {
        $stmt = $this->pdo->prepare('UPDATE lx_customer SET lexware_contact_id = :cid WHERE id = :id');
        $stmt->execute([':cid' => $contactId, ':id' => $id]);
    }

    /** @return list<array<string,mixed>> */
    public function projects(int $customerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, customer_id, title, hourly_rate, status FROM lx_project
             WHERE customer_id = :cid ORDER BY status ASC, title ASC'
        );
        $stmt->execute([':cid' => $customerId]);
        return array_map([self::class, 'mapProject'], $stmt->fetchAll());
    }

    public function createProject(int $customerId, string $title, ?float $hourlyRate, string $status): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO lx_project (customer_id, title, hourly_rate, status)
             VALUES (:cid, :title, :rate, :status)'
        );
        $stmt->execute([':cid' => $customerId, ':title' => $title, ':rate' => $hourlyRate, ':status' => $status]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * A project joined with its customer (name + Lexware contact id + rates) —
     * the source for building an invoice. @return array<string,mixed>|null
     */
    public function findProjectWithCustomer(int $projectId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.title, p.hourly_rate, p.status,
                    c.id AS customer_id, c.name AS customer_name, c.lexware_contact_id,
                    c.default_hourly_rate, c.tax_rate_percent
             FROM lx_project p INNER JOIN lx_customer c ON c.id = p.customer_id
             WHERE p.id = :id'
        );
        $stmt->execute([':id' => $projectId]);
        $r = $stmt->fetch();
        if ($r === false) {
            return null;
        }
        return [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'hourly_rate' => $r['hourly_rate'] !== null ? (float) $r['hourly_rate'] : null,
            'status' => (string) $r['status'],
            'customer_id' => (int) $r['customer_id'],
            'customer_name' => (string) $r['customer_name'],
            'lexware_contact_id' => $r['lexware_contact_id'] !== null ? (string) $r['lexware_contact_id'] : null,
            'default_hourly_rate' => $r['default_hourly_rate'] !== null ? (float) $r['default_hourly_rate'] : null,
            'tax_rate_percent' => $r['tax_rate_percent'] !== null ? (float) $r['tax_rate_percent'] : null,
        ];
    }

    /** @param array<string,mixed> $r */
    private static function mapCustomer(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'email' => $r['email'] !== null ? (string) $r['email'] : null,
            'lexware_contact_id' => $r['lexware_contact_id'] !== null ? (string) $r['lexware_contact_id'] : null,
            'default_hourly_rate' => $r['default_hourly_rate'] !== null ? (float) $r['default_hourly_rate'] : null,
            'tax_rate_percent' => $r['tax_rate_percent'] !== null ? (float) $r['tax_rate_percent'] : null,
            'note' => ($r['note'] ?? null) !== null ? (string) $r['note'] : null,
            'project_count' => isset($r['project_count']) ? (int) $r['project_count'] : null,
        ];
    }

    /** @param array<string,mixed> $r */
    private static function mapProject(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'customer_id' => (int) $r['customer_id'],
            'title' => (string) $r['title'],
            'hourly_rate' => $r['hourly_rate'] !== null ? (float) $r['hourly_rate'] : null,
            'status' => (string) $r['status'],
        ];
    }
}
