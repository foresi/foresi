<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The pairing of a received document with a planned occurrence.
 */
final class Version20261123180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the document pairings of Cash flow';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('cash_flow_document_pairing');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('subject_type', Types::STRING, ['length' => 40]);
        $table->addColumn('subject_id', Types::STRING, ['length' => 64]);
        $table->addColumn('planned_item_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('occurrence_on', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('state', Types::STRING, ['length' => 16]);
        $table->addColumn('candidates', Types::JSON);
        $table->addColumn('counterparty_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('decided_by', Types::GUID, ['notnull' => false]);
        $table->addColumn('decided_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['subject_type', 'subject_id'], 'uniq_cash_flow_document_pairing_subject');
        $table->addIndex(['planned_item_id', 'occurrence_on'], 'idx_cash_flow_document_pairing_occurrence');
        $table->addIndex(['counterparty_id'], 'idx_cash_flow_document_pairing_counterparty');
        $table->addForeignKeyConstraint('cash_flow_planned_item', ['planned_item_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_cash_flow_document_pairing_planned_item');
        $table->addForeignKeyConstraint('party', ['counterparty_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_cash_flow_document_pairing_counterparty');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('cash_flow_document_pairing');
    }
}
