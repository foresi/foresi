<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recurring invoice templates and their lines. Columns carry the names of the same
 * fields on invoice and invoice_line; a line holds the price as typed and the kind
 * of rate, never a rate or an amount.
 */
final class Version20261103000803 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add recurring invoice templates and their lines';
    }

    public function up(Schema $schema): void
    {
        $template = $schema->createTable('recurring_invoice');
        $template->addColumn('id', Types::GUID);
        $template->addColumn('tenant_id', 'tenant_id');
        $template->addColumn('legal_entity_id', Types::GUID);
        $template->addColumn('series_id', Types::GUID, ['notnull' => false]);
        $template->addColumn('customer_id', Types::GUID);
        $template->addColumn('version', Types::INTEGER);
        $template->addColumn('currency', Types::STRING, ['length' => 3, 'fixed' => true]);
        $template->addColumn('payment_method', Types::STRING, ['length' => 32]);
        $template->addColumn('bank_account', Types::STRING, ['length' => 34, 'notnull' => false]);
        $template->addColumn('constant_symbol', Types::STRING, ['length' => 10]);
        $template->addColumn('order_number', Types::STRING, ['length' => 64]);
        $template->addColumn('description', Types::TEXT);
        $template->addColumn('text_before', Types::TEXT);
        $template->addColumn('text_after', Types::TEXT);
        $template->addColumn('language', Types::STRING, ['length' => 2, 'fixed' => true]);
        $template->addColumn('first_issue_on', Types::DATE_IMMUTABLE);
        $template->addColumn('recurrence_unit', Types::STRING, ['length' => 8]);
        $template->addColumn('recurrence_every', Types::SMALLINT);
        $template->addColumn('on_last_day_of_month', Types::BOOLEAN);
        $template->addColumn('recurrence_end', Types::STRING, ['length' => 16]);
        $template->addColumn('end_on', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $template->addColumn('end_after', Types::SMALLINT, ['notnull' => false]);
        $template->addColumn('tax_point', Types::STRING, ['length' => 32]);
        $template->addColumn('due_days', Types::SMALLINT);
        $template->addColumn('delivery', Types::STRING, ['length' => 16]);
        $template->addColumn('state', Types::STRING, ['length' => 16]);
        $template->addColumn('next_occurrence', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $template->addColumn('last_occurrence', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $template->addColumn('issued_count', Types::INTEGER);
        $template->addColumn('paused_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $template->addColumn('ended_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $template->addColumn('last_failure_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $template->addColumn('last_failure_code', Types::STRING, ['length' => 64, 'notnull' => false]);
        $template->addColumn('last_alert_on', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $template->addColumn('owner_id', Types::GUID, ['notnull' => false]);
        $template->addColumn('imported_from', Types::STRING, ['length' => 64, 'notnull' => false]);
        $template->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $template->setPrimaryKey(['id']);
        $template->addIndex(['tenant_id'], 'idx_recurring_invoice_tenant');
        $template->addIndex(['tenant_id', 'state', 'next_occurrence'], 'idx_recurring_invoice_due');
        $template->addIndex(['legal_entity_id'], 'idx_recurring_invoice_firm');
        $template->addIndex(['series_id'], 'idx_recurring_invoice_series');
        $template->addIndex(['customer_id'], 'idx_recurring_invoice_customer');
        $template->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_recurring_invoice_firm');
        $template->addForeignKeyConstraint('series', ['series_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_recurring_invoice_series');
        $template->addForeignKeyConstraint('party', ['customer_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_recurring_invoice_customer');

        $line = $schema->createTable('recurring_invoice_line');
        $line->addColumn('id', Types::GUID);
        $line->addColumn('tenant_id', 'tenant_id');
        $line->addColumn('recurring_invoice_id', Types::GUID);
        $line->addColumn('position', Types::INTEGER);
        $line->addColumn('text', Types::TEXT);
        $line->addColumn('quantity', Types::BIGINT);
        $line->addColumn('unit', Types::STRING, ['length' => 32]);
        $line->addColumn('price_minor', Types::BIGINT);
        $line->addColumn('price_type', Types::STRING, ['length' => 16]);
        $line->addColumn('treatment', Types::STRING, ['length' => 16]);
        $line->addColumn('supply_code', Types::STRING, ['length' => 8, 'notnull' => false]);
        $line->addColumn('rate_kind', Types::STRING, ['length' => 16, 'notnull' => false]);
        $line->addColumn('discount', Types::STRING, ['length' => 6]);
        $line->setPrimaryKey(['id']);
        $line->addIndex(['tenant_id'], 'idx_recurring_invoice_line_tenant');
        $line->addIndex(['recurring_invoice_id'], 'idx_recurring_invoice_line_template');
        $line->addUniqueIndex(['recurring_invoice_id', 'position'], 'uniq_recurring_invoice_line_position');
        $line->addForeignKeyConstraint('recurring_invoice', ['recurring_invoice_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_recurring_invoice_line_template');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('recurring_invoice_line');
        $schema->dropTable('recurring_invoice');
    }
}
