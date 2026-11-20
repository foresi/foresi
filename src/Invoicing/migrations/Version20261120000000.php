<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The baseline of Invoicing: its 14 tables as the global migrations left
 * them (ADR 0034). A database migrated before the module packages moves onto
 * it with app:migrations:rebase, which runs none of this.
 */
final class Version20261120000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the tables of Invoicing';
    }

    public function up(Schema $schema): void
    {
        $this->abandonedNumber($schema);
        $this->firmInvoicing($schema);
        $this->firmMailServer($schema);
        $this->firmReminders($schema);
        $this->invoice($schema);
        $this->invoiceLine($schema);
        $this->invoiceSend($schema);
        $this->mailTemplate($schema);
        $this->payment($schema);
        $this->paymentAllocation($schema);
        $this->recurringInvoice($schema);
        $this->recurringInvoiceLine($schema);
        $this->series($schema);
        $this->seriesCounter($schema);
    }

    /**
     * A table of one row keeps it so: the unique one_row column, and on
     * PostgreSQL a check that it is true. SQLite cannot add a check to a
     * table, so there the unique index alone stops a second row.
     */
    public function postUp(Schema $schema): void
    {
        // A dry run creates no table to check.
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform
            || !$this->connection->createSchemaManager()->tablesExist(['firm_invoicing'])) {
            return;
        }
        $this->connection->executeStatement('ALTER TABLE firm_invoicing ADD CONSTRAINT chk_firm_invoicing_one_row CHECK (one_row)');
        $this->connection->executeStatement('ALTER TABLE firm_mail_server ADD CONSTRAINT chk_firm_mail_server_one_row CHECK (one_row)');
        $this->connection->executeStatement('ALTER TABLE firm_reminders ADD CONSTRAINT chk_firm_reminders_one_row CHECK (one_row)');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('series_counter');
        $schema->dropTable('series');
        $schema->dropTable('recurring_invoice_line');
        $schema->dropTable('recurring_invoice');
        $schema->dropTable('payment_allocation');
        $schema->dropTable('payment');
        $schema->dropTable('mail_template');
        $schema->dropTable('invoice_send');
        $schema->dropTable('invoice_line');
        $schema->dropTable('invoice');
        $schema->dropTable('firm_reminders');
        $schema->dropTable('firm_mail_server');
        $schema->dropTable('firm_invoicing');
        $schema->dropTable('abandoned_number');
    }

    private function abandonedNumber(Schema $schema): void
    {
        $table = $schema->createTable('abandoned_number');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('invoice_id', Types::GUID);
        $table->addColumn('number', Types::STRING, ['length' => 80]);
        $table->addColumn('number_key', Types::STRING, ['length' => 80]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['invoice_id'], 'idx_abandoned_number_invoice');
        $table->addUniqueIndex(['number_key'], 'uniq_abandoned_number');
        $table->addForeignKeyConstraint('invoice', ['invoice_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_abandoned_number_invoice');
    }

    private function firmInvoicing(Schema $schema): void
    {
        $table = $schema->createTable('firm_invoicing');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('rounding', Types::STRING, ['length' => 16]);
        $table->addColumn('constant_symbol', Types::STRING, ['length' => 10]);
        $table->addColumn('text_before', Types::TEXT);
        $table->addColumn('text_after', Types::TEXT);
        $table->addColumn('contact_email', Types::STRING, ['length' => 255]);
        $table->addColumn('contact_phone', Types::STRING, ['length' => 32]);
        $table->addColumn('contact_web', Types::STRING, ['length' => 255]);
        $table->addColumn('one_row', Types::BOOLEAN, ['default' => true]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['one_row'], 'uniq_firm_invoicing_one_row');
    }

    private function firmMailServer(Schema $schema): void
    {
        $table = $schema->createTable('firm_mail_server');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('host', Types::STRING, ['length' => 253]);
        $table->addColumn('port', Types::INTEGER);
        $table->addColumn('encryption', Types::STRING, ['length' => 16]);
        $table->addColumn('username', Types::STRING, ['length' => 255]);
        $table->addColumn('secret_sealed', Types::TEXT);
        $table->addColumn('from_address', Types::STRING, ['length' => 254]);
        $table->addColumn('from_name', Types::STRING, ['length' => 255]);
        $table->addColumn('verified_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('last_test_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('last_test_error', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('one_row', Types::BOOLEAN, ['default' => true]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['one_row'], 'uniq_firm_mail_server_one_row');
    }

    private function firmReminders(Schema $schema): void
    {
        $table = $schema->createTable('firm_reminders');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('enabled', Types::BOOLEAN, ['default' => false]);
        $table->addColumn('enabled_on', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('first_after_days', Types::SMALLINT);
        $table->addColumn('every_days', Types::SMALLINT);
        $table->addColumn('minimum_minor', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('minimum_currency', Types::STRING, ['length' => 3, 'fixed' => true, 'notnull' => false]);
        $table->addColumn('remind_advances', Types::BOOLEAN, ['default' => false]);
        $table->addColumn('one_row', Types::BOOLEAN, ['default' => true]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['one_row'], 'uniq_firm_reminders_one_row');
    }

    private function invoice(Schema $schema): void
    {
        $table = $schema->createTable('invoice');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('series_id', Types::GUID);
        $table->addColumn('customer_id', Types::GUID);
        $table->addColumn('version', Types::INTEGER);
        $table->addColumn('kind', Types::STRING, ['length' => 32]);
        $table->addColumn('number', Types::STRING, ['length' => 80]);
        $table->addColumn('number_key', Types::STRING, ['length' => 80]);
        $table->addColumn('vat_regime', Types::STRING, ['length' => 16]);
        $table->addColumn('rounding', Types::STRING, ['length' => 16]);
        $table->addColumn('currency', Types::STRING, ['length' => 3, 'fixed' => true]);
        $table->addColumn('exchange_rate', Types::STRING, ['length' => 32]);
        $table->addColumn('customer_name', Types::STRING, ['length' => 255]);
        $table->addColumn('customer_registration_number', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('customer_vat_id', Types::STRING, ['length' => 14, 'notnull' => false]);
        $table->addColumn('customer_address_street', Types::STRING, ['length' => 255]);
        $table->addColumn('customer_address_city', Types::STRING, ['length' => 128]);
        $table->addColumn('customer_address_postal_code', Types::STRING, ['length' => 32]);
        $table->addColumn('customer_address_country_code', Types::STRING, ['length' => 2, 'fixed' => true]);
        $table->addColumn('customer_register_entry', Types::STRING, ['length' => 255]);
        $table->addColumn('customer_email', Types::STRING, ['length' => 254, 'notnull' => false]);
        $table->addColumn('customer_phone', Types::STRING, ['length' => 64]);
        $table->addColumn('customer_web', Types::STRING, ['length' => 255]);
        $table->addColumn('customer_logo', Types::STRING, ['length' => 72, 'notnull' => false]);
        $table->addColumn('customer_stamp', Types::STRING, ['length' => 72, 'notnull' => false]);
        $table->addColumn('issuer_name', Types::STRING, ['length' => 255]);
        $table->addColumn('issuer_registration_number', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('issuer_vat_id', Types::STRING, ['length' => 14, 'notnull' => false]);
        $table->addColumn('issuer_address_street', Types::STRING, ['length' => 255]);
        $table->addColumn('issuer_address_city', Types::STRING, ['length' => 128]);
        $table->addColumn('issuer_address_postal_code', Types::STRING, ['length' => 32]);
        $table->addColumn('issuer_address_country_code', Types::STRING, ['length' => 2, 'fixed' => true]);
        $table->addColumn('issuer_register_entry', Types::STRING, ['length' => 255]);
        $table->addColumn('issuer_email', Types::STRING, ['length' => 254, 'notnull' => false]);
        $table->addColumn('issuer_phone', Types::STRING, ['length' => 64]);
        $table->addColumn('issuer_web', Types::STRING, ['length' => 255]);
        $table->addColumn('issuer_logo', Types::STRING, ['length' => 72, 'notnull' => false]);
        $table->addColumn('issuer_stamp', Types::STRING, ['length' => 72, 'notnull' => false]);
        $table->addColumn('issue_date', Types::DATE_IMMUTABLE);
        $table->addColumn('tax_point_date', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('due_date', Types::DATE_IMMUTABLE);
        $table->addColumn('payment_method', Types::STRING, ['length' => 32]);
        $table->addColumn('bank_account', Types::STRING, ['length' => 34, 'notnull' => false]);
        $table->addColumn('variable_symbol', Types::STRING, ['length' => 10]);
        $table->addColumn('constant_symbol', Types::STRING, ['length' => 10]);
        $table->addColumn('order_number', Types::STRING, ['length' => 64]);
        $table->addColumn('description', Types::TEXT);
        $table->addColumn('text_before', Types::TEXT);
        $table->addColumn('text_after', Types::TEXT);
        $table->addColumn('language', Types::STRING, ['length' => 2, 'fixed' => true]);
        $table->addColumn('net_minor', Types::BIGINT);
        $table->addColumn('vat_minor', Types::BIGINT);
        $table->addColumn('rounding_minor', Types::BIGINT);
        $table->addColumn('payable_minor', Types::BIGINT);
        $table->addColumn('delivered_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('deleted_by', Types::GUID, ['notnull' => false]);
        $table->addColumn('deleted_reason', Types::TEXT, ['notnull' => false]);
        $table->addColumn('created_by', Types::GUID, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('recurring_invoice_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('recurring_occurrence', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('tax_on_receipt', Types::STRING, ['length' => 8, 'notnull' => false]);
        $table->addColumn('advance_invoice_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('payment_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('deducted_by_invoice_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('deducts_advances', Types::BOOLEAN, ['default' => false]);
        $table->addColumn('corrected_invoice_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('correction_reason', Types::TEXT, ['notnull' => false]);
        $table->addColumn('correction_type', Types::STRING, ['length' => 8, 'notnull' => false]);
        $table->addColumn('decisive_date', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('exported_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('changed_after_export_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('import_source', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('import_external_id', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('import_run_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('imported_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('original_pdf_key', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('original_pdf_sha256', Types::STRING, ['length' => 64, 'fixed' => true, 'notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['corrected_invoice_id'], 'idx_invoice_corrected');
        $table->addIndex(['customer_id'], 'idx_invoice_customer');
        $table->addIndex(['deducted_by_invoice_id'], 'idx_invoice_deducted_by');
        $table->addIndex(['import_run_id'], 'idx_invoice_import_run');
        $table->addIndex(['issue_date'], 'idx_invoice_issue_date');
        $table->addIndex(['payment_id'], 'idx_invoice_payment');
        $table->addIndex(['series_id'], 'idx_invoice_series');
        $table->addIndex(['tax_point_date'], 'idx_invoice_tax_point');
        $table->addUniqueIndex(['import_source', 'import_external_id'], 'uniq_invoice_import');
        $table->addUniqueIndex(['number_key'], 'uniq_invoice_number');
        $table->addUniqueIndex(['recurring_invoice_id', 'recurring_occurrence'], 'uniq_invoice_occurrence');
        $table->addUniqueIndex(['advance_invoice_id', 'payment_id'], 'uniq_invoice_taxed_payment');
        $table->addForeignKeyConstraint('party', ['customer_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_invoice_customer');
        $table->addForeignKeyConstraint('series', ['series_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_invoice_series');
    }

    private function invoiceLine(Schema $schema): void
    {
        $table = $schema->createTable('invoice_line');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('invoice_id', Types::GUID);
        $table->addColumn('position', Types::INTEGER);
        $table->addColumn('text', Types::TEXT);
        $table->addColumn('quantity', Types::BIGINT);
        $table->addColumn('unit', Types::STRING, ['length' => 32]);
        $table->addColumn('price_minor', Types::BIGINT);
        $table->addColumn('price_type', Types::STRING, ['length' => 16]);
        $table->addColumn('treatment', Types::STRING, ['length' => 16]);
        $table->addColumn('supply_code', Types::STRING, ['length' => 8, 'notnull' => false]);
        $table->addColumn('rate_kind', Types::STRING, ['length' => 16, 'notnull' => false]);
        $table->addColumn('rate', Types::STRING, ['length' => 6, 'notnull' => false]);
        $table->addColumn('discount', Types::STRING, ['length' => 6]);
        $table->addColumn('unit_net_minor', Types::BIGINT);
        $table->addColumn('net_minor', Types::BIGINT);
        $table->addColumn('vat_minor', Types::BIGINT);
        $table->addColumn('gross_minor', Types::BIGINT);
        $table->addColumn('deducts_invoice_id', Types::GUID, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['deducts_invoice_id'], 'idx_invoice_line_deducts');
        $table->addIndex(['invoice_id'], 'idx_invoice_line_invoice');
        $table->addUniqueIndex(['invoice_id', 'position'], 'uniq_invoice_line_position');
        $table->addForeignKeyConstraint('invoice', ['invoice_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_invoice_line_invoice');
    }

    private function invoiceSend(Schema $schema): void
    {
        $table = $schema->createTable('invoice_send');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('invoice_id', Types::GUID);
        $table->addColumn('status', Types::STRING, ['length' => 16]);
        $table->addColumn('recipients_to', Types::JSON);
        $table->addColumn('recipients_cc', Types::JSON);
        $table->addColumn('copy_to', Types::STRING, ['length' => 254, 'notnull' => false]);
        $table->addColumn('subject', Types::STRING, ['length' => 255]);
        $table->addColumn('body', Types::TEXT);
        $table->addColumn('language', Types::STRING, ['length' => 2, 'fixed' => true]);
        $table->addColumn('invoice_version', Types::INTEGER);
        $table->addColumn('created_by', Types::GUID, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('sent_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('failed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('cancelled_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('attempts', Types::INTEGER);
        $table->addColumn('last_attempt_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('last_error', Types::STRING, ['length' => 500, 'notnull' => false]);
        $table->addColumn('settings_problem', Types::BOOLEAN);
        $table->addColumn('message_id', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('claimed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('token_hash', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('copy_token_hash', Types::STRING, ['length' => 64, 'fixed' => true, 'notnull' => false]);
        $table->addColumn('copy_sent_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('copy_error', Types::STRING, ['length' => 500, 'notnull' => false]);
        $table->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('first_read_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('read_count', Types::INTEGER);
        $table->addColumn('pdf_key', Types::STRING, ['length' => 255]);
        $table->addColumn('pdf_sha256', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('document_number', Types::STRING, ['length' => 80]);
        $table->addColumn('issuer_name', Types::STRING, ['length' => 255]);
        $table->addColumn('customer_name', Types::STRING, ['length' => 255]);
        $table->addColumn('amount_minor', Types::BIGINT);
        $table->addColumn('currency', Types::STRING, ['length' => 3, 'fixed' => true]);
        $table->addColumn('due_date', Types::DATE_IMMUTABLE);
        $table->addColumn('requeued_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('payment_method', Types::STRING, ['length' => 32, 'default' => 'bank_transfer']);
        $table->addColumn('bank_account', Types::STRING, ['length' => 34, 'notnull' => false]);
        $table->addColumn('domestic_account', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('variable_symbol', Types::STRING, ['length' => 10, 'default' => '']);
        $table->addColumn('payment_code', Types::TEXT, ['notnull' => false]);
        $table->addColumn('last_error_code', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('last_error_smtp', Types::SMALLINT, ['notnull' => false]);
        $table->addColumn('copy_error_code', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('copy_error_smtp', Types::SMALLINT, ['notnull' => false]);
        $table->addColumn('purpose', Types::STRING, ['length' => 16, 'default' => 'invoice']);
        $table->addColumn('reminder_step', Types::SMALLINT, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['invoice_id'], 'idx_invoice_send_invoice');
        $table->addIndex(['status', 'created_at'], 'idx_invoice_send_status');
        $table->addUniqueIndex(['copy_token_hash'], 'uniq_invoice_send_copy_token');
        $table->addUniqueIndex(['token_hash'], 'uniq_invoice_send_token');
        $table->addUniqueIndex(['invoice_id', 'reminder_step'], 'uniq_send_reminder_step');
        $table->addForeignKeyConstraint('invoice', ['invoice_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_invoice_send_invoice');
    }

    private function mailTemplate(Schema $schema): void
    {
        $table = $schema->createTable('mail_template');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('kind', Types::STRING, ['length' => 16]);
        $table->addColumn('language', Types::STRING, ['length' => 8]);
        $table->addColumn('subject', Types::STRING, ['length' => 255]);
        $table->addColumn('body', Types::TEXT);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['kind', 'language'], 'uniq_mail_template_kind_language');
    }

    private function payment(Schema $schema): void
    {
        $table = $schema->createTable('payment');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('received_on', Types::DATE_IMMUTABLE);
        $table->addColumn('amount_minor', Types::BIGINT);
        $table->addColumn('currency', Types::STRING, ['length' => 3, 'fixed' => true]);
        $table->addColumn('method', Types::STRING, ['length' => 16]);
        $table->addColumn('source', Types::STRING, ['length' => 16]);
        $table->addColumn('bank_movement_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('note', Types::STRING, ['length' => 500]);
        $table->addColumn('created_by', Types::GUID, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('cancelled_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('cancelled_by', Types::GUID, ['notnull' => false]);
        $table->addColumn('live_bank_movement_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('direction', Types::STRING, ['length' => 8, 'default' => 'incoming']);
        $table->addColumn('import_source', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('import_external_id', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('import_run_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('imported_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['bank_movement_id'], 'idx_payment_bank_movement');
        $table->addIndex(['import_run_id'], 'idx_payment_import_run');
        $table->addIndex(['received_on'], 'idx_payment_received');
        $table->addUniqueIndex(['live_bank_movement_id'], 'uniq_payment_active_movement');
        $table->addUniqueIndex(['import_source', 'import_external_id'], 'uniq_payment_import');
    }

    private function paymentAllocation(Schema $schema): void
    {
        $table = $schema->createTable('payment_allocation');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('payment_id', Types::GUID);
        $table->addColumn('invoice_id', Types::GUID);
        $table->addColumn('amount_minor', Types::BIGINT);
        $table->addColumn('settlement_minor', Types::BIGINT, ['default' => '0']);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['invoice_id'], 'idx_payment_allocation_invoice');
        $table->addIndex(['payment_id'], 'idx_payment_allocation_payment');
        $table->addForeignKeyConstraint('invoice', ['invoice_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_payment_allocation_invoice');
        $table->addForeignKeyConstraint('payment', ['payment_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_payment_allocation_payment');
    }

    private function recurringInvoice(Schema $schema): void
    {
        $table = $schema->createTable('recurring_invoice');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('series_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('customer_id', Types::GUID);
        $table->addColumn('version', Types::INTEGER);
        $table->addColumn('currency', Types::STRING, ['length' => 3, 'fixed' => true]);
        $table->addColumn('payment_method', Types::STRING, ['length' => 32]);
        $table->addColumn('bank_account', Types::STRING, ['length' => 34, 'notnull' => false]);
        $table->addColumn('constant_symbol', Types::STRING, ['length' => 10]);
        $table->addColumn('order_number', Types::STRING, ['length' => 64]);
        $table->addColumn('description', Types::TEXT);
        $table->addColumn('text_before', Types::TEXT);
        $table->addColumn('text_after', Types::TEXT);
        $table->addColumn('language', Types::STRING, ['length' => 2, 'fixed' => true]);
        $table->addColumn('first_issue_on', Types::DATE_IMMUTABLE);
        $table->addColumn('recurrence_unit', Types::STRING, ['length' => 8]);
        $table->addColumn('recurrence_every', Types::SMALLINT);
        $table->addColumn('on_last_day_of_month', Types::BOOLEAN);
        $table->addColumn('recurrence_end', Types::STRING, ['length' => 16]);
        $table->addColumn('end_on', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('end_after', Types::SMALLINT, ['notnull' => false]);
        $table->addColumn('tax_point', Types::STRING, ['length' => 32]);
        $table->addColumn('due_days', Types::SMALLINT);
        $table->addColumn('delivery', Types::STRING, ['length' => 16]);
        $table->addColumn('state', Types::STRING, ['length' => 16]);
        $table->addColumn('next_occurrence', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('last_occurrence', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('issued_count', Types::INTEGER);
        $table->addColumn('paused_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('ended_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('last_failure_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('last_failure_code', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('last_alert_on', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('owner_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('imported_from', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('name', Types::STRING, ['length' => 120, 'default' => '']);
        $table->addColumn('copy_to_me', Types::BOOLEAN, ['default' => true]);
        $table->addColumn('copy_to_others', Types::BOOLEAN, ['default' => true]);
        $table->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('deleted_by', Types::GUID, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['customer_id'], 'idx_recurring_invoice_customer');
        $table->addIndex(['deleted_at'], 'idx_recurring_invoice_deleted');
        $table->addIndex(['state', 'next_occurrence'], 'idx_recurring_invoice_due');
        $table->addIndex(['series_id'], 'idx_recurring_invoice_series');
        $table->addForeignKeyConstraint('party', ['customer_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_recurring_invoice_customer');
        $table->addForeignKeyConstraint('series', ['series_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_recurring_invoice_series');
    }

    private function recurringInvoiceLine(Schema $schema): void
    {
        $table = $schema->createTable('recurring_invoice_line');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('recurring_invoice_id', Types::GUID);
        $table->addColumn('position', Types::INTEGER);
        $table->addColumn('text', Types::TEXT);
        $table->addColumn('quantity', Types::BIGINT);
        $table->addColumn('unit', Types::STRING, ['length' => 32]);
        $table->addColumn('price_minor', Types::BIGINT);
        $table->addColumn('price_type', Types::STRING, ['length' => 16]);
        $table->addColumn('treatment', Types::STRING, ['length' => 16]);
        $table->addColumn('supply_code', Types::STRING, ['length' => 8, 'notnull' => false]);
        $table->addColumn('rate_kind', Types::STRING, ['length' => 16, 'notnull' => false]);
        $table->addColumn('discount', Types::STRING, ['length' => 6]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['recurring_invoice_id'], 'idx_recurring_invoice_line_template');
        $table->addUniqueIndex(['recurring_invoice_id', 'position'], 'uniq_recurring_invoice_line_position');
        $table->addForeignKeyConstraint('recurring_invoice', ['recurring_invoice_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_recurring_invoice_line_template');
    }

    private function series(Schema $schema): void
    {
        $table = $schema->createTable('series');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('name', Types::STRING, ['length' => 100]);
        $table->addColumn('format', Types::STRING, ['length' => 64]);
        $table->addColumn('kind', Types::STRING, ['length' => 32]);
        $table->addColumn('default_flag', Types::BOOLEAN, ['notnull' => false]);
        $table->addColumn('starts', Types::JSON);
        $table->addColumn('archived', Types::BOOLEAN);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['kind', 'default_flag'], 'uniq_series_default');
        $table->addUniqueIndex(['name'], 'uniq_series_name');
    }

    private function seriesCounter(Schema $schema): void
    {
        $table = $schema->createTable('series_counter');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('series_id', Types::GUID);
        $table->addColumn('period', Types::STRING, ['length' => 8]);
        $table->addColumn('last_value', Types::INTEGER);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['series_id'], 'idx_9929ab8a5278319c');
        $table->addUniqueIndex(['series_id', 'period'], 'uniq_series_counter');
        $table->addForeignKeyConstraint('series', ['series_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_series_counter_series');
    }
}
