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
 * How each received document came in, and the reading of its file. A document
 * with an original file was uploaded; any other was typed in, as the API
 * records none yet that it can tell apart. A reading goes with its document.
 * One firm per instance (ADR 0039), so no firm id.
 */
final class Version20261124140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the source of received documents and the reading of their files';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('received_document')->addColumn('source', Types::STRING, ['length' => 16, 'default' => 'manual']);

        $table = $schema->createTable('purchasing_document_reading');
        $table->addColumn('document_id', Types::GUID);
        $table->addColumn('state', Types::STRING, ['length' => 16]);
        $table->addColumn('read_values', Types::JSON);
        $table->addColumn('applied', Types::JSON);
        $table->addColumn('suggested', Types::JSON);
        $table->addColumn('base_version', Types::INTEGER);
        $table->addColumn('warnings', Types::JSON);
        $table->addColumn('attempts', Types::INTEGER);
        $table->addColumn('failure', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('requested_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('finished_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['document_id']);
        $table->addForeignKeyConstraint('received_document', ['document_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_purchasing_document_reading_document');
    }

    public function postUp(Schema $schema): void
    {
        // A dry run adds no column to fill.
        if (!$this->connection->createSchemaManager()->tablesExist(['purchasing_document_reading'])) {
            return;
        }
        $this->connection->executeStatement("UPDATE received_document SET source = 'upload' WHERE original_attachment_id IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('purchasing_document_reading');
        $schema->getTable('received_document')->dropColumn('source');
    }
}
