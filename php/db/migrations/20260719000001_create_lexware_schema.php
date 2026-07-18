<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Lexware billing-hub schema. The class name is prefixed with the module id
 * (`Lexware*`): the base API's in-process auto-migrator includes every module's
 * migrations into ONE PHP process, so a reused class name is an uncatchable
 * fatal redeclaration. Every table is `lx_`-prefixed against collisions in the
 * shared schema.
 *
 * MySQL-8-safe: `signed => false` on every id/FK column (prod is MySQL 8, which
 * refuses a signed FK column against an unsigned PK).
 */
final class CreateLexwareSchema extends AbstractMigration
{
    public function change(): void
    {
        // --- Customer directory ------------------------------------------------
        $this->table('lx_customer', ['signed' => false])
            ->addColumn('name', 'string', ['limit' => 200])
            ->addColumn('email', 'string', ['limit' => 254, 'null' => true])
            ->addColumn('lexware_contact_id', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('default_hourly_rate', 'decimal', ['precision' => 10, 'scale' => 2, 'null' => true])
            ->addColumn('tax_rate_percent', 'decimal', ['precision' => 5, 'scale' => 2, 'null' => true])
            ->addColumn('note', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['email'], ['unique' => true, 'name' => 'uq_lx_customer_email'])
            ->create();

        // --- Projects (a customer's billable work streams) ---------------------
        $this->table('lx_project', ['signed' => false])
            ->addColumn('customer_id', 'integer', ['signed' => false])
            ->addColumn('title', 'string', ['limit' => 200])
            ->addColumn('hourly_rate', 'decimal', ['precision' => 10, 'scale' => 2, 'null' => true])
            ->addColumn('status', 'enum', ['values' => ['active', 'archived'], 'default' => 'active'])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['customer_id'])
            ->addForeignKey('customer_id', 'lx_customer', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();

        // --- Time-entry → project assignment -----------------------------------
        // time_entry_id references tds-ext-time-tracker's `time_entry` table but
        // carries NO cross-domain FK (that table is another extension's — same
        // rule as ticket.customer_id). A unique index keeps one link per entry.
        $this->table('lx_time_link', ['signed' => false])
            ->addColumn('time_entry_id', 'integer', ['signed' => false])
            ->addColumn('project_id', 'integer', ['signed' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['time_entry_id'], ['unique' => true, 'name' => 'uq_lx_time_link_entry'])
            ->addIndex(['project_id'])
            ->addForeignKey('project_id', 'lx_project', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();

        // --- Contact-sync dedupe map (source → Lexware contact) ----------------
        $this->table('lx_contact_map', ['signed' => false])
            ->addColumn('source_type', 'enum', ['values' => ['lx_customer', 'contact_message', 'ticket']])
            ->addColumn('source_id', 'integer', ['signed' => false])
            ->addColumn('lexware_contact_id', 'string', ['limit' => 50])
            ->addColumn('synced_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['source_type', 'source_id'], ['unique' => true, 'name' => 'uq_lx_contact_map_source'])
            ->create();

        // --- Invoice audit log (one row per created Lexware invoice) ------------
        $this->table('lx_invoice_log', ['signed' => false])
            ->addColumn('lexware_invoice_id', 'string', ['limit' => 50])
            ->addColumn('resource_uri', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('customer_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('project_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('period_from', 'date', ['null' => true])
            ->addColumn('period_to', 'date', ['null' => true])
            ->addColumn('total_minutes', 'integer', ['default' => 0])
            ->addColumn('line_item_count', 'integer', ['default' => 0])
            ->addColumn('finalized', 'boolean', ['default' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['customer_id'])
            ->addForeignKey('customer_id', 'lx_customer', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();
    }
}
