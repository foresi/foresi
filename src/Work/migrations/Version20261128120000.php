<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recurring tasks (plan 37.4): the pattern under a project, and on each task the
 * recurring task and the date it was created for, once per date.
 */
final class Version20261128120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add recurring tasks and link each task to the one that created it';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('recurring_task');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('version', Types::INTEGER);
        $table->addColumn('project_id', Types::GUID);
        $table->addColumn('title', Types::STRING, ['length' => 500]);
        $table->addColumn('description', Types::TEXT);
        $table->addColumn('section_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('responsible_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('priority', Types::STRING, ['length' => 16]);
        $table->addColumn('private', Types::BOOLEAN);
        $table->addColumn('estimate_minutes', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('label_ids', Types::JSON);
        $table->addColumn('watcher_ids', Types::JSON);
        $table->addColumn('custom_fields', Types::JSON);
        $table->addColumn('subtasks', Types::JSON);
        $table->addColumn('first_on', Types::DATE_IMMUTABLE);
        $table->addColumn('recurrence_unit', Types::STRING, ['length' => 8]);
        $table->addColumn('recurrence_every', Types::SMALLINT);
        $table->addColumn('on_last_day_of_month', Types::BOOLEAN);
        $table->addColumn('recurrence_end', Types::STRING, ['length' => 16]);
        $table->addColumn('end_on', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('end_after', Types::SMALLINT, ['notnull' => false]);
        $table->addColumn('create_days_before', Types::SMALLINT);
        $table->addColumn('due_days_after', Types::SMALLINT, ['notnull' => false]);
        $table->addColumn('state', Types::STRING, ['length' => 16]);
        $table->addColumn('next_occurrence', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('last_occurrence', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('created_count', Types::INTEGER);
        $table->addColumn('skipped_on', Types::JSON);
        $table->addColumn('paused_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('ended_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('last_failure_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('last_failure_code', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('owner_id', Types::GUID);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['state', 'next_occurrence'], 'idx_recurring_task_due');
        $table->addIndex(['project_id'], 'idx_recurring_task_project');
        $table->addIndex(['section_id'], 'idx_recurring_task_section');
        $table->addIndex(['responsible_id'], 'idx_recurring_task_responsible');
        $table->addForeignKeyConstraint('project', ['project_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_recurring_task_project');
        $table->addForeignKeyConstraint('project_section', ['section_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_recurring_task_section');
        $table->addForeignKeyConstraint('membership', ['responsible_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_recurring_task_responsible');

        $task = $schema->getTable('task');
        $task->addColumn('recurring_task_id', Types::GUID, ['notnull' => false]);
        $task->addColumn('occurrence_on', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $task->addUniqueIndex(['recurring_task_id', 'occurrence_on'], 'uniq_task_occurrence');
        $task->addForeignKeyConstraint('recurring_task', ['recurring_task_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_task_recurring_task');
    }

    public function down(Schema $schema): void
    {
        $task = $schema->getTable('task');
        $task->removeForeignKey('fk_task_recurring_task');
        $task->dropIndex('uniq_task_occurrence');
        $task->dropColumn('occurrence_on');
        $task->dropColumn('recurring_task_id');
        $schema->dropTable('recurring_task');
    }
}
