<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Invoices, their lines and the numbers edits gave up. New tables, so nothing to
 * backfill. Payments, corrections and sending will refer to invoice.id.
 */
final class Version20261102000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add invoices, their lines and abandoned numbers';
    }

    public function up(Schema $schema): void
    {
        $invoice = $schema->createTable('invoice');
        $invoice->addColumn('id', Types::GUID);
        $invoice->addColumn('tenant_id', 'tenant_id');
        $invoice->addColumn('legal_entity_id', Types::GUID);
        $invoice->addColumn('series_id', Types::GUID);
        $invoice->addColumn('customer_id', Types::GUID);
        $invoice->addColumn('version', Types::INTEGER);
        $invoice->addColumn('kind', Types::STRING, ['length' => 32]);
        $invoice->addColumn('number', Types::STRING, ['length' => 80]);
        $invoice->addColumn('number_key', Types::STRING, ['length' => 80]);
        $invoice->addColumn('vat_regime', Types::STRING, ['length' => 16]);
        $invoice->addColumn('rounding', Types::STRING, ['length' => 16]);
        $invoice->addColumn('currency', Types::STRING, ['length' => 3, 'fixed' => true]);
        $invoice->addColumn('exchange_rate', Types::STRING, ['length' => 32]);
        $this->addParty($invoice, 'customer_');
        $this->addParty($invoice, 'issuer_');
        $invoice->addColumn('issue_date', Types::DATE_IMMUTABLE);
        $invoice->addColumn('tax_point_date', Types::DATE_IMMUTABLE);
        $invoice->addColumn('due_date', Types::DATE_IMMUTABLE);
        $invoice->addColumn('payment_method', Types::STRING, ['length' => 32]);
        $invoice->addColumn('bank_account', Types::STRING, ['length' => 34, 'notnull' => false]);
        $invoice->addColumn('variable_symbol', Types::STRING, ['length' => 10]);
        $invoice->addColumn('constant_symbol', Types::STRING, ['length' => 10]);
        $invoice->addColumn('order_number', Types::STRING, ['length' => 64]);
        $invoice->addColumn('description', Types::TEXT);
        $invoice->addColumn('text_before', Types::TEXT);
        $invoice->addColumn('text_after', Types::TEXT);
        $invoice->addColumn('language', Types::STRING, ['length' => 2, 'fixed' => true]);
        $invoice->addColumn('net_minor', Types::BIGINT);
        $invoice->addColumn('vat_minor', Types::BIGINT);
        $invoice->addColumn('rounding_minor', Types::BIGINT);
        $invoice->addColumn('payable_minor', Types::BIGINT);
        $invoice->addColumn('delivered_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $invoice->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $invoice->addColumn('deleted_by', Types::GUID, ['notnull' => false]);
        $invoice->addColumn('deleted_reason', Types::TEXT, ['notnull' => false]);
        $invoice->addColumn('created_by', Types::GUID, ['notnull' => false]);
        $invoice->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $invoice->setPrimaryKey(['id']);
        $invoice->addIndex(['tenant_id'], 'idx_invoice_tenant');
        $invoice->addIndex(['tenant_id', 'legal_entity_id', 'issue_date'], 'idx_invoice_firm_date');
        $invoice->addIndex(['series_id'], 'idx_invoice_series');
        $invoice->addIndex(['customer_id'], 'idx_invoice_customer');
        $invoice->addUniqueIndex(['legal_entity_id', 'number_key'], 'uniq_invoice_number');
        $invoice->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_invoice_firm');
        $invoice->addForeignKeyConstraint('series', ['series_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_invoice_series');
        $invoice->addForeignKeyConstraint('party', ['customer_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_invoice_customer');

        $line = $schema->createTable('invoice_line');
        $line->addColumn('id', Types::GUID);
        $line->addColumn('tenant_id', 'tenant_id');
        $line->addColumn('invoice_id', Types::GUID);
        $line->addColumn('position', Types::INTEGER);
        $line->addColumn('text', Types::TEXT);
        $line->addColumn('quantity', Types::BIGINT);
        $line->addColumn('unit', Types::STRING, ['length' => 32]);
        $line->addColumn('price_minor', Types::BIGINT);
        $line->addColumn('price_type', Types::STRING, ['length' => 16]);
        $line->addColumn('treatment', Types::STRING, ['length' => 16]);
        $line->addColumn('supply_code', Types::STRING, ['length' => 8, 'notnull' => false]);
        $line->addColumn('rate_kind', Types::STRING, ['length' => 16, 'notnull' => false]);
        $line->addColumn('rate', Types::STRING, ['length' => 6, 'notnull' => false]);
        $line->addColumn('discount', Types::STRING, ['length' => 6]);
        $line->addColumn('unit_net_minor', Types::BIGINT);
        $line->addColumn('net_minor', Types::BIGINT);
        $line->addColumn('vat_minor', Types::BIGINT);
        $line->addColumn('gross_minor', Types::BIGINT);
        $line->setPrimaryKey(['id']);
        $line->addIndex(['tenant_id'], 'idx_invoice_line_tenant');
        $line->addIndex(['invoice_id'], 'idx_invoice_line_invoice');
        $line->addUniqueIndex(['invoice_id', 'position'], 'uniq_invoice_line_position');
        $line->addForeignKeyConstraint('invoice', ['invoice_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_invoice_line_invoice');

        $abandoned = $schema->createTable('abandoned_number');
        $abandoned->addColumn('id', Types::GUID);
        $abandoned->addColumn('tenant_id', 'tenant_id');
        $abandoned->addColumn('legal_entity_id', Types::GUID);
        $abandoned->addColumn('invoice_id', Types::GUID);
        $abandoned->addColumn('number', Types::STRING, ['length' => 80]);
        $abandoned->addColumn('number_key', Types::STRING, ['length' => 80]);
        $abandoned->setPrimaryKey(['id']);
        $abandoned->addIndex(['tenant_id'], 'idx_abandoned_number_tenant');
        $abandoned->addIndex(['invoice_id'], 'idx_abandoned_number_invoice');
        $abandoned->addUniqueIndex(['legal_entity_id', 'number_key'], 'uniq_abandoned_number');
        $abandoned->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_abandoned_number_firm');
        $abandoned->addForeignKeyConstraint('invoice', ['invoice_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_abandoned_number_invoice');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('abandoned_number');
        $schema->dropTable('invoice_line');
        $schema->dropTable('invoice');
    }

    private function addParty(Table $table, string $prefix): void
    {
        $table->addColumn($prefix.'name', Types::STRING, ['length' => 255]);
        $table->addColumn($prefix.'registration_number', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn($prefix.'vat_id', Types::STRING, ['length' => 14, 'notnull' => false]);
        $table->addColumn($prefix.'address_street', Types::STRING, ['length' => 255]);
        $table->addColumn($prefix.'address_city', Types::STRING, ['length' => 128]);
        $table->addColumn($prefix.'address_postal_code', Types::STRING, ['length' => 32]);
        $table->addColumn($prefix.'address_country_code', Types::STRING, ['length' => 2, 'fixed' => true]);
        $table->addColumn($prefix.'register_entry', Types::STRING, ['length' => 255]);
        $table->addColumn($prefix.'email', Types::STRING, ['length' => 254, 'notnull' => false]);
        $table->addColumn($prefix.'phone', Types::STRING, ['length' => 64]);
        $table->addColumn($prefix.'web', Types::STRING, ['length' => 255]);
        $table->addColumn($prefix.'logo', Types::STRING, ['length' => 72, 'notnull' => false]);
        $table->addColumn($prefix.'stamp', Types::STRING, ['length' => 72, 'notnull' => false]);
    }
}
