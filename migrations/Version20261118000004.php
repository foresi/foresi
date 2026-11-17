<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One instance serves one customer (ADR 0035): the invoicing tables lose their
 * tenant column. The guard (Version20261117210001) made sure the database holds
 * at most one tenant. The indexes that led with the tenant are rebuilt on their
 * remaining columns under the same names, the unique ones unique again: the
 * occurrence of a recurring template, the reminder step of an invoice and the
 * name of a series within a firm. The plain tenant indexes go with the column.
 *
 * Going down adds the column back, not null, so it works only while these
 * tables are empty. With data, restore the dump taken before this migration.
 */
final class Version20261118000004 extends AbstractMigration
{
    /** Each table with its plain tenant index. */
    private const array TENANT_INDEXES = [
        'invoice' => 'idx_invoice_tenant',
        'invoice_line' => 'idx_invoice_line_tenant',
        'invoice_send' => 'idx_invoice_send_tenant',
        'series' => 'idx_series_tenant',
        'series_counter' => 'idx_series_counter_tenant',
        'payment' => 'idx_payment_tenant',
        'payment_allocation' => 'idx_payment_allocation_tenant',
        'recurring_invoice' => 'idx_recurring_invoice_tenant',
        'recurring_invoice_line' => 'idx_recurring_invoice_line_tenant',
        'firm_invoicing' => 'idx_firm_invoicing_tenant',
        'firm_reminders' => 'idx_firm_reminders_tenant',
        'mail_template' => 'idx_mail_template_tenant',
        'firm_mail_server' => 'idx_firm_mail_server_tenant',
        'abandoned_number' => 'idx_abandoned_number_tenant',
    ];

    /** The indexes that led with the tenant: table, name, the columns after the tenant, unique. */
    private const array LED_BY_TENANT = [
        ['invoice', 'idx_invoice_firm_date', ['legal_entity_id', 'issue_date'], false],
        ['invoice', 'idx_invoice_firm_tax_point', ['legal_entity_id', 'tax_point_date'], false],
        ['invoice', 'uniq_invoice_occurrence', ['recurring_invoice_id', 'recurring_occurrence'], true],
        ['invoice_send', 'idx_invoice_send_status', ['status', 'created_at'], false],
        ['invoice_send', 'uniq_send_reminder_step', ['invoice_id', 'reminder_step'], true],
        ['payment', 'idx_payment_firm_received', ['legal_entity_id', 'received_on'], false],
        ['recurring_invoice', 'idx_recurring_invoice_due', ['state', 'next_occurrence'], false],
        ['series', 'uniq_series_name', ['legal_entity_id', 'name'], true],
    ];

    public function getDescription(): string
    {
        return 'Drop the tenant column from the invoicing tables';
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
            $schema->getTable($table)->addColumn('tenant_id', 'tenant_id', ['notnull' => true]);
            $schema->getTable($table)->addIndex(['tenant_id'], $index);
        }
        foreach (self::LED_BY_TENANT as [$table, $name, $columns, $unique]) {
            $unique
                ? $schema->getTable($table)->addUniqueIndex(['tenant_id', ...$columns], $name)
                : $schema->getTable($table)->addIndex(['tenant_id', ...$columns], $name);
        }
    }
}
