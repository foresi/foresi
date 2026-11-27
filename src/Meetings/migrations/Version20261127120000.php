<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The action items of Meetings (plan 38.3): which task was made from which
 * meeting. The task is an id of Work's, without a foreign key to its table.
 */
final class Version20261127120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the action items of Meetings';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('meetings_action_item');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('meeting_id', Types::GUID);
        $table->addColumn('task_id', Types::GUID);
        $table->addColumn('created_by', Types::GUID);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['meeting_id'], 'idx_meetings_action_item_meeting');
        $table->addIndex(['task_id'], 'idx_meetings_action_item_task');
        $table->addForeignKeyConstraint('meetings_meeting', ['meeting_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_meetings_action_item_meeting');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('meetings_action_item');
    }
}
