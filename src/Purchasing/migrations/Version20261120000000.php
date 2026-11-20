<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The baseline of Purchasing: its 4 tables as the global migrations left
 * them (ADR 0034). A database migrated before the module packages moves onto
 * it with app:migrations:rebase, which runs none of this.
 */
final class Version20261120000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the tables of Purchasing';
    }

    public function up(Schema $schema): void
    {
        $this->evidenceCounter($schema);
        $this->evidenceSeries($schema);
        $this->receivedDocument($schema);
        $this->receivedDocumentPayment($schema);
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
            || !$this->connection->createSchemaManager()->tablesExist(['evidence_series'])) {
            return;
        }
        $this->connection->executeStatement('ALTER TABLE evidence_series ADD CONSTRAINT chk_evidence_series_one_row CHECK (one_row)');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('received_document_payment');
        $schema->dropTable('received_document');
        $schema->dropTable('evidence_series');
        $schema->dropTable('evidence_counter');
    }

    private function evidenceCounter(Schema $schema): void
    {
        $table = $schema->createTable('evidence_counter');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('evidence_series_id', Types::GUID);
        $table->addColumn('period', Types::STRING, ['length' => 8]);
        $table->addColumn('last_value', Types::INTEGER);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['evidence_series_id'], 'idx_29fba2e9f598f787');
        $table->addUniqueIndex(['evidence_series_id', 'period'], 'uniq_evidence_counter');
        $table->addForeignKeyConstraint('evidence_series', ['evidence_series_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_evidence_counter_series');
    }

    private function evidenceSeries(Schema $schema): void
    {
        $table = $schema->createTable('evidence_series');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('format', Types::STRING, ['length' => 32]);
        $table->addColumn('one_row', Types::BOOLEAN, ['default' => true]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['one_row'], 'uniq_evidence_series_one_row');
    }

    private function receivedDocument(Schema $schema): void
    {
        $table = $schema->createTable('received_document');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('version', Types::INTEGER);
        $table->addColumn('state', Types::STRING, ['length' => 16]);
        $table->addColumn('kind', Types::STRING, ['length' => 32]);
        $table->addColumn('treatment', Types::STRING, ['length' => 32]);
        $table->addColumn('evidence_number', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('supplier_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('supplier_name', Types::STRING, ['length' => 255]);
        $table->addColumn('supplier_registration_number', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('supplier_vat_id', Types::STRING, ['length' => 14, 'notnull' => false]);
        $table->addColumn('supplier_address_street', Types::STRING, ['length' => 255]);
        $table->addColumn('supplier_address_city', Types::STRING, ['length' => 128]);
        $table->addColumn('supplier_address_postal_code', Types::STRING, ['length' => 32]);
        $table->addColumn('supplier_address_country_code', Types::STRING, ['length' => 2, 'fixed' => true]);
        $table->addColumn('supplier_document_number', Types::STRING, ['length' => 64]);
        $table->addColumn('variable_symbol', Types::STRING, ['length' => 10]);
        $table->addColumn('issue_date', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('tax_point_date', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('due_date', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('received_date', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('currency', Types::STRING, ['length' => 3, 'fixed' => true]);
        $table->addColumn('total_minor', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('base_minor', Types::BIGINT);
        $table->addColumn('vat_minor', Types::BIGINT);
        $table->addColumn('base_czk_minor', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('vat_czk_minor', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('exchange_rate', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('exchange_rate_date', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('vat_recap', Types::JSON);
        $table->addColumn('deduction_period', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('latest_deduction_date', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('original_attachment_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('original_document_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('recorded_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('recorded_by', Types::GUID, ['notnull' => false]);
        $table->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('deleted_by', Types::GUID, ['notnull' => false]);
        $table->addColumn('deleted_reason', Types::TEXT, ['notnull' => false]);
        $table->addColumn('created_by', Types::GUID, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('supply_code', Types::STRING, ['length' => 8, 'notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['deduction_period'], 'idx_received_document_deduction');
        $table->addIndex(['original_document_id'], 'idx_received_document_original');
        $table->addIndex(['received_date'], 'idx_received_document_received');
        $table->addIndex(['supplier_id'], 'idx_received_document_supplier');
        $table->addUniqueIndex(['evidence_number'], 'uniq_received_document_evidence');
        $table->addForeignKeyConstraint('party', ['supplier_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_received_document_supplier');
    }

    private function receivedDocumentPayment(Schema $schema): void
    {
        $table = $schema->createTable('received_document_payment');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('received_document_id', Types::GUID);
        $table->addColumn('paid_on', Types::DATE_IMMUTABLE);
        $table->addColumn('amount_minor', Types::BIGINT);
        $table->addColumn('currency', Types::STRING, ['length' => 3, 'fixed' => true]);
        $table->addColumn('method', Types::STRING, ['length' => 16]);
        $table->addColumn('bank_movement_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('created_by', Types::GUID);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['received_document_id'], 'idx_received_document_payment_document');
        $table->addIndex(['bank_movement_id'], 'idx_received_document_payment_movement');
        $table->addForeignKeyConstraint('received_document', ['received_document_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_received_document_payment_document');
    }
}
