<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * The tables of Documents, and the NDA document type (P4 of plan 32): it needs
 * signing at the simple level. Its name "NDA" reads the same in every language
 * the instance offers; people rename it, or add their own types, in the settings.
 */
final class Version20261122200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the tables of Documents with the NDA type';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('documents_type');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('name', Types::STRING, ['length' => 120]);
        $table->addColumn('code', Types::STRING, ['length' => 40]);
        $table->addColumn('needs_signing', Types::BOOLEAN);
        $table->addColumn('signature_level', Types::STRING, ['length' => 16]);
        $table->addColumn('archived_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['code'], 'uniq_documents_type_code');

        $table = $schema->createTable('documents_template');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('type_id', Types::GUID);
        $table->addColumn('name', Types::STRING, ['length' => 120]);
        $table->addColumn('archived_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['type_id'], 'idx_documents_template_type');

        $table = $schema->createTable('documents_template_version');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('template_id', Types::GUID);
        $table->addColumn('number', Types::INTEGER);
        $table->addColumn('body', Types::TEXT);
        $table->addColumn('paper', Types::STRING, ['length' => 8]);
        $table->addColumn('landscape', Types::BOOLEAN);
        $table->addColumn('margin_top_mm', Types::FLOAT);
        $table->addColumn('margin_right_mm', Types::FLOAT);
        $table->addColumn('margin_bottom_mm', Types::FLOAT);
        $table->addColumn('margin_left_mm', Types::FLOAT);
        $table->addColumn('frozen_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['template_id', 'number'], 'uniq_documents_template_version_number');

        $table = $schema->createTable('documents_document');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('type_id', Types::GUID);
        $table->addColumn('template_id', Types::GUID);
        $table->addColumn('template_version_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('title', Types::STRING, ['length' => 200]);
        $table->addColumn('status', Types::STRING, ['length' => 24]);
        $table->addColumn('resolved_values', Types::JSON, ['notnull' => false]);
        $table->addColumn('pdf_attachment_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('pdf_sha256', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('signed_attachment_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('signed_sha256', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('certificate_attachment_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('certificate_sha256', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('provider', Types::STRING, ['length' => 40, 'notnull' => false]);
        $table->addColumn('envelope_id', Types::STRING, ['length' => 128, 'notnull' => false]);
        $table->addColumn('created_by', Types::GUID);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('generated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('sent_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('completed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('closed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['type_id'], 'idx_documents_document_type');
        $table->addIndex(['status'], 'idx_documents_document_status');

        $table = $schema->createTable('documents_document_link');
        $table->addColumn('document_id', Types::GUID);
        $table->addColumn('kind', Types::STRING, ['length' => 40]);
        $table->addColumn('target_id', Types::GUID);
        $table->setPrimaryKey(['document_id', 'kind', 'target_id']);
        $table->addIndex(['kind', 'target_id'], 'idx_documents_document_link_target');
        $table->addForeignKeyConstraint('documents_document', ['document_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_documents_document_link_document');

        $table = $schema->createTable('documents_signer');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('document_id', Types::GUID);
        $table->addColumn('name', Types::STRING, ['length' => 160]);
        $table->addColumn('email', Types::STRING, ['length' => 254, 'notnull' => false]);
        $table->addColumn('party_person_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('side', Types::STRING, ['length' => 8]);
        $table->addColumn('sign_order', Types::INTEGER);
        $table->addColumn('status', Types::STRING, ['length' => 16]);
        $table->addColumn('signed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('method', Types::STRING, ['length' => 40, 'notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['document_id'], 'idx_documents_signer_document');
        $table->addForeignKeyConstraint('documents_document', ['document_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_documents_signer_document');
    }

    public function postUp(Schema $schema): void
    {
        // A dry run creates no table to fill.
        if (!$this->connection->createSchemaManager()->tablesExist(['documents_type'])) {
            return;
        }
        $this->connection->insert('documents_type', [
            'id' => Uuid::v7()->toRfc4122(),
            'name' => 'NDA',
            'code' => 'nda',
            'needs_signing' => true,
            'signature_level' => 'simple',
            'archived_at' => null,
            'created_at' => new \DateTimeImmutable(),
        ], ['needs_signing' => Types::BOOLEAN, 'created_at' => Types::DATETIME_IMMUTABLE]);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('documents_signer');
        $schema->dropTable('documents_document_link');
        $schema->dropTable('documents_document');
        $schema->dropTable('documents_template_version');
        $schema->dropTable('documents_template');
        $schema->dropTable('documents_type');
    }
}
