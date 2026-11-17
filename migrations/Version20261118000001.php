<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One instance serves one customer (ADR 0035): the tables of cash flow,
 * purchasing, the timesheet and the iDoklad move lose their tenant column. The
 * guard before (Version20261117210001) made sure the database holds at most one
 * tenant, so no rows of two tenants meet in a unique index. The indexes that
 * led with the tenant are rebuilt on their remaining columns under the same
 * names; the plain tenant indexes go with the column.
 *
 * Going down adds the column back, not null, so it works only while these
 * tables are empty. With data, restore the dump taken before this migration.
 */
final class Version20261118000001 extends AbstractMigration
{
    /** Each table with its plain tenant index. */
    private const array TENANT_INDEXES = [
        'cash_flow_checkpoint' => 'idx_cash_flow_checkpoint_tenant',
        'cash_flow_expected_date' => 'idx_cash_flow_expected_date_tenant',
        'cash_flow_minimum_balance' => 'idx_cash_flow_minimum_balance_tenant',
        'cash_flow_planned_item' => 'idx_cash_flow_planned_item_tenant',
        'evidence_counter' => 'idx_evidence_counter_tenant',
        'evidence_series' => 'idx_evidence_series_tenant',
        'idoklad_connection' => 'idx_idoklad_connection_tenant',
        'idoklad_import_run' => 'idx_idoklad_import_run_tenant',
        'idoklad_job' => 'idx_idoklad_job_tenant',
        'idoklad_link' => 'idx_idoklad_link_tenant',
        'idoklad_pull' => 'idx_idoklad_pull_tenant',
        'idoklad_record' => 'idx_idoklad_record_tenant',
        'received_document' => 'idx_received_document_tenant',
        'received_document_payment' => 'idx_received_document_payment_tenant',
        'released_time' => 'idx_released_time_tenant',
    ];

    /** The indexes that led with the tenant: table, name, the columns after the tenant, unique. */
    private const array LED_BY_TENANT = [
        ['cash_flow_expected_date', 'uniq_cash_flow_expected_date_subject', ['subject_type', 'subject_id'], true],
        ['received_document', 'idx_received_document_firm_received', ['legal_entity_id', 'received_date'], false],
        ['received_document', 'idx_received_document_firm_deduction', ['legal_entity_id', 'deduction_period'], false],
    ];

    public function getDescription(): string
    {
        return 'Drop the tenant column from the cash flow, purchasing, timesheet and iDoklad tables';
    }

    public function up(Schema $schema): void
    {
        foreach (self::LED_BY_TENANT as [$table, $name]) {
            $schema->getTable($table)->dropIndex($name);
        }
        foreach (self::TENANT_INDEXES as $table => $index) {
            $schema->getTable($table)->dropIndex($index);
            $schema->getTable($table)->dropColumn('tenant_id');
        }
        foreach (self::LED_BY_TENANT as [$table, $name, $columns, $unique]) {
            $unique
                ? $schema->getTable($table)->addUniqueIndex($columns, $name)
                : $schema->getTable($table)->addIndex($columns, $name);
        }
    }

    public function preDown(Schema $schema): void
    {
        foreach (array_keys(self::TENANT_INDEXES) as $table) {
            $this->abortIf(
                false !== $this->connection->fetchOne('SELECT 1 FROM '.$table),
                \sprintf('The table %s holds rows, which have no tenant to go back to: restore the dump taken before this migration instead.', $table),
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::LED_BY_TENANT as [$table, $name]) {
            $schema->getTable($table)->dropIndex($name);
        }
        foreach (self::TENANT_INDEXES as $table => $index) {
            $schema->getTable($table)->addColumn('tenant_id', 'guid', ['notnull' => true]);
            $schema->getTable($table)->addIndex(['tenant_id'], $index);
        }
        foreach (self::LED_BY_TENANT as [$table, $name, $columns, $unique]) {
            $unique
                ? $schema->getTable($table)->addUniqueIndex(['tenant_id', ...$columns], $name)
                : $schema->getTable($table)->addIndex(['tenant_id', ...$columns], $name);
        }
    }
}
