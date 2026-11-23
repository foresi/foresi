<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The baseline of the Margin module: how the cost of a line or a document is
 * mapped to recipients, and the shares of each document that follow from it.
 * No foreign keys: the documents belong to an optional module.
 */
final class Version20261124100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the margin line and document allocations and the cost shares';
    }

    public function up(Schema $schema): void
    {
        $line = $schema->createTable('margin_line_allocation');
        $line->addColumn('id', Types::GUID);
        $line->addColumn('document_id', Types::GUID);
        $line->addColumn('line_key', Types::STRING, ['length' => 64]);
        $line->addColumn('allocation', Types::JSON);
        $line->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $line->addColumn('updated_by', Types::STRING, ['length' => 64]);
        $line->setPrimaryKey(['id']);
        $line->addUniqueIndex(['document_id', 'line_key'], 'uniq_margin_line_allocation');

        $document = $schema->createTable('margin_document_allocation');
        $document->addColumn('id', Types::GUID);
        $document->addColumn('document_id', Types::GUID);
        $document->addColumn('recipient', Types::STRING, ['length' => 36]);
        $document->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $document->addColumn('updated_by', Types::STRING, ['length' => 64]);
        $document->setPrimaryKey(['id']);
        $document->addUniqueIndex(['document_id'], 'uniq_margin_document_allocation');

        $share = $schema->createTable('margin_cost_share');
        $share->addColumn('id', Types::GUID);
        $share->addColumn('document_id', Types::GUID);
        $share->addColumn('line_key', Types::STRING, ['length' => 64]);
        $share->addColumn('month', Types::DATE_IMMUTABLE);
        $share->addColumn('recipient', Types::STRING, ['length' => 36, 'notnull' => false]);
        $share->addColumn('amount_czk_minor', Types::BIGINT);
        $share->addColumn('source', Types::STRING, ['length' => 16]);
        $share->addColumn('planned_item_id', Types::GUID, ['notnull' => false]);
        $share->addColumn('occurrence_on', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $share->setPrimaryKey(['id']);
        $share->addIndex(['document_id'], 'idx_margin_cost_share_document');
        $share->addIndex(['month'], 'idx_margin_cost_share_month');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('margin_cost_share');
        $schema->dropTable('margin_document_allocation');
        $schema->dropTable('margin_line_allocation');
    }
}
