<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The suggestions agents propose on inbox items, and their steps.
 */
final class Version20261126140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the inbox suggestions and their steps';
    }

    public function up(Schema $schema): void
    {
        $suggestion = $schema->createTable('inbox_suggestion');
        $suggestion->addColumn('id', Types::GUID);
        $suggestion->addColumn('item_id', Types::GUID);
        $suggestion->addColumn('state', Types::STRING, ['length' => 16]);
        $suggestion->addColumn('rationale', Types::TEXT);
        $suggestion->addColumn('quote', Types::TEXT);
        $suggestion->addColumn('quote_offset', Types::INTEGER);
        $suggestion->addColumn('proposed_by_user_id', Types::GUID);
        $suggestion->addColumn('proposed_by_token_id', Types::GUID);
        $suggestion->addColumn('proposed_by_client', Types::STRING, ['length' => 100]);
        $suggestion->addColumn('proposed_at', Types::DATETIME_IMMUTABLE);
        $suggestion->addColumn('superseded_by', Types::GUID, ['notnull' => false]);
        $suggestion->addColumn('decided_by', Types::GUID, ['notnull' => false]);
        $suggestion->addColumn('decided_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $suggestion->addColumn('reject_reason', Types::STRING, ['length' => 500, 'notnull' => false]);
        $suggestion->setPrimaryKey(['id']);
        $suggestion->addIndex(['item_id', 'state'], 'idx_inbox_suggestion_item_state');
        $suggestion->addForeignKeyConstraint('inbox_item', ['item_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_inbox_suggestion_item');

        $step = $schema->createTable('inbox_suggestion_step');
        $step->addColumn('id', Types::GUID);
        $step->addColumn('suggestion_id', Types::GUID);
        $step->addColumn('position', Types::INTEGER);
        $step->addColumn('operation', Types::STRING, ['length' => 100]);
        $step->addColumn('proposed_input', Types::JSON);
        $step->addColumn('final_input', Types::JSON, ['notnull' => false]);
        $step->addColumn('selected', Types::BOOLEAN);
        $step->addColumn('state', Types::STRING, ['length' => 16]);
        $step->addColumn('record_kind', Types::STRING, ['length' => 40, 'notnull' => false]);
        $step->addColumn('record_id', Types::GUID, ['notnull' => false]);
        $step->addColumn('refusal', Types::JSON, ['notnull' => false]);
        $step->addColumn('ran_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $step->setPrimaryKey(['id']);
        $step->addUniqueIndex(['suggestion_id', 'position'], 'uniq_inbox_suggestion_step_position');
        $step->addForeignKeyConstraint('inbox_suggestion', ['suggestion_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_inbox_suggestion_step_suggestion');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('inbox_suggestion_step');
        $schema->dropTable('inbox_suggestion');
    }
}
