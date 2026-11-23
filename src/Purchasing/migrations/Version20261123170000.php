<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The lines of received documents (plan 34a): each splits a base of the VAT
 * recap by what the cost was for, at its position on the document. They go
 * with their document. One firm per instance (ADR 0039), so no firm id.
 */
final class Version20261123170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the lines of received documents';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('received_document_line');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('document_id', Types::GUID);
        $table->addColumn('position', Types::INTEGER);
        $table->addColumn('text', Types::STRING, ['length' => 255]);
        $table->addColumn('base_minor', Types::BIGINT);
        $table->addColumn('vat_rate', Types::STRING, ['length' => 6]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['document_id'], 'idx_received_document_line_document');
        $table->addUniqueIndex(['document_id', 'position'], 'uniq_received_document_line_position');
        $table->addForeignKeyConstraint('received_document', ['document_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_received_document_line_document');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('received_document_line');
    }
}
