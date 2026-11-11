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
 * Received documents (ADR 0028): the record per firm with the frozen supplier,
 * the dates, the totals, the VAT recap as one JSON value, the deduction dates
 * and the trash. The original file and a credit note's original document are
 * named by id without a foreign key, as the attachment store and the trash
 * decide their own lifetimes. A new table, so nothing existing needs a value.
 */
final class Version20261110200002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add received documents';
    }

    public function up(Schema $schema): void
    {
        $document = $schema->createTable('received_document');
        $document->addColumn('id', Types::GUID);
        $document->addColumn('tenant_id', 'tenant_id');
        $document->addColumn('legal_entity_id', Types::GUID);
        $document->addColumn('version', Types::INTEGER);
        $document->addColumn('state', Types::STRING, ['length' => 16]);
        $document->addColumn('kind', Types::STRING, ['length' => 32]);
        $document->addColumn('treatment', Types::STRING, ['length' => 32]);
        $document->addColumn('evidence_number', Types::STRING, ['length' => 64, 'notnull' => false]);
        $document->addColumn('supplier_id', Types::GUID, ['notnull' => false]);
        $document->addColumn('supplier_name', Types::STRING, ['length' => 255]);
        $document->addColumn('supplier_registration_number', Types::STRING, ['length' => 64, 'notnull' => false]);
        $document->addColumn('supplier_vat_id', Types::STRING, ['length' => 14, 'notnull' => false]);
        $document->addColumn('supplier_address_street', Types::STRING, ['length' => 255]);
        $document->addColumn('supplier_address_city', Types::STRING, ['length' => 128]);
        $document->addColumn('supplier_address_postal_code', Types::STRING, ['length' => 32]);
        $document->addColumn('supplier_address_country_code', Types::STRING, ['length' => 2, 'fixed' => true]);
        $document->addColumn('supplier_document_number', Types::STRING, ['length' => 64]);
        $document->addColumn('variable_symbol', Types::STRING, ['length' => 10]);
        $document->addColumn('issue_date', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $document->addColumn('tax_point_date', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $document->addColumn('due_date', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $document->addColumn('received_date', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $document->addColumn('currency', Types::STRING, ['length' => 3, 'fixed' => true]);
        $document->addColumn('total_minor', Types::BIGINT, ['notnull' => false]);
        $document->addColumn('base_minor', Types::BIGINT);
        $document->addColumn('vat_minor', Types::BIGINT);
        $document->addColumn('base_czk_minor', Types::BIGINT, ['notnull' => false]);
        $document->addColumn('vat_czk_minor', Types::BIGINT, ['notnull' => false]);
        $document->addColumn('exchange_rate', Types::STRING, ['length' => 32, 'notnull' => false]);
        $document->addColumn('exchange_rate_date', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $document->addColumn('vat_recap', Types::JSON);
        $document->addColumn('deduction_period', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $document->addColumn('latest_deduction_date', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $document->addColumn('original_attachment_id', Types::GUID, ['notnull' => false]);
        $document->addColumn('original_document_id', Types::GUID, ['notnull' => false]);
        $document->addColumn('recorded_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $document->addColumn('recorded_by', Types::GUID, ['notnull' => false]);
        $document->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $document->addColumn('deleted_by', Types::GUID, ['notnull' => false]);
        $document->addColumn('deleted_reason', Types::TEXT, ['notnull' => false]);
        $document->addColumn('created_by', Types::GUID, ['notnull' => false]);
        $document->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $document->setPrimaryKey(['id']);
        $document->addIndex(['tenant_id'], 'idx_received_document_tenant');
        $document->addIndex(['tenant_id', 'legal_entity_id', 'received_date'], 'idx_received_document_firm_received');
        $document->addIndex(['tenant_id', 'legal_entity_id', 'deduction_period'], 'idx_received_document_firm_deduction');
        $document->addIndex(['supplier_id'], 'idx_received_document_supplier');
        $document->addIndex(['original_document_id'], 'idx_received_document_original');
        $document->addUniqueIndex(['legal_entity_id', 'evidence_number'], 'uniq_received_document_evidence');
        $document->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_received_document_firm');
        $document->addForeignKeyConstraint('party', ['supplier_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_received_document_supplier');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('received_document');
    }
}
