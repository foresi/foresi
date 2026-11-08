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
 * Projects with their statuses and sections, tasks with their watchers, and
 * the labels of the organisation. New tables only: nothing existing changes.
 */
final class Version20261107120001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add projects, statuses, sections, tasks, watchers and labels';
    }

    public function up(Schema $schema): void
    {
        $project = $schema->createTable('project');
        $project->addColumn('id', Types::GUID);
        $project->addColumn('tenant_id', 'tenant_id');
        $project->addColumn('name', Types::STRING, ['length' => 200]);
        $project->addColumn('client_id', Types::GUID, ['notnull' => false]);
        $project->addColumn('description', Types::TEXT);
        $project->addColumn('workflow_template', Types::STRING, ['length' => 16]);
        $project->addColumn('archived_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $project->addColumn('created_by', Types::GUID);
        $project->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $project->setPrimaryKey(['id']);
        $project->addIndex(['tenant_id'], 'idx_project_tenant');
        $project->addIndex(['client_id'], 'idx_project_client');
        $project->addForeignKeyConstraint('party', ['client_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_project_client');

        $status = $schema->createTable('project_status');
        $status->addColumn('id', Types::GUID);
        $status->addColumn('tenant_id', 'tenant_id');
        $status->addColumn('project_id', Types::GUID);
        $status->addColumn('name', Types::STRING, ['length' => 80]);
        $status->addColumn('type', Types::STRING, ['length' => 16]);
        $status->addColumn('position', Types::INTEGER);
        $status->setPrimaryKey(['id']);
        $status->addIndex(['tenant_id'], 'idx_project_status_tenant');
        $status->addIndex(['project_id', 'position'], 'idx_project_status_position');
        $status->addForeignKeyConstraint('project', ['project_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_project_status_project');

        $section = $schema->createTable('project_section');
        $section->addColumn('id', Types::GUID);
        $section->addColumn('tenant_id', 'tenant_id');
        $section->addColumn('project_id', Types::GUID);
        $section->addColumn('name', Types::STRING, ['length' => 120]);
        $section->addColumn('position', Types::INTEGER);
        $section->setPrimaryKey(['id']);
        $section->addIndex(['tenant_id'], 'idx_project_section_tenant');
        $section->addIndex(['project_id', 'position'], 'idx_project_section_position');
        $section->addForeignKeyConstraint('project', ['project_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_project_section_project');

        $task = $schema->createTable('task');
        $task->addColumn('id', Types::GUID);
        $task->addColumn('tenant_id', 'tenant_id');
        $task->addColumn('project_id', Types::GUID);
        $task->addColumn('section_id', Types::GUID, ['notnull' => false]);
        $task->addColumn('parent_id', Types::GUID, ['notnull' => false]);
        $task->addColumn('title', Types::STRING, ['length' => 500]);
        $task->addColumn('description', Types::TEXT);
        $task->addColumn('status_id', Types::GUID);
        $task->addColumn('responsible_id', Types::GUID, ['notnull' => false]);
        $task->addColumn('due_on', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $task->addColumn('priority', Types::STRING, ['length' => 16]);
        $task->addColumn('position', Types::INTEGER);
        $task->addColumn('completed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $task->addColumn('created_by', Types::GUID);
        $task->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $task->setPrimaryKey(['id']);
        $task->addIndex(['tenant_id'], 'idx_task_tenant');
        $task->addIndex(['tenant_id', 'project_id', 'position'], 'idx_task_project_position');
        $task->addIndex(['tenant_id', 'responsible_id', 'due_on'], 'idx_task_responsible_due');
        $task->addIndex(['project_id'], 'idx_task_project');
        $task->addIndex(['section_id'], 'idx_task_section');
        $task->addIndex(['parent_id'], 'idx_task_parent');
        $task->addIndex(['status_id'], 'idx_task_status');
        $task->addIndex(['responsible_id'], 'idx_task_responsible');
        $task->addForeignKeyConstraint('project', ['project_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_task_project');
        $task->addForeignKeyConstraint('project_section', ['section_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_task_section');
        $task->addForeignKeyConstraint('task', ['parent_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_task_parent');
        $task->addForeignKeyConstraint('project_status', ['status_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_task_status');
        $task->addForeignKeyConstraint('membership', ['responsible_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_task_responsible');

        $watcher = $schema->createTable('task_watcher');
        $watcher->addColumn('id', Types::GUID);
        $watcher->addColumn('tenant_id', 'tenant_id');
        $watcher->addColumn('task_id', Types::GUID);
        $watcher->addColumn('membership_id', Types::GUID);
        $watcher->setPrimaryKey(['id']);
        $watcher->addIndex(['tenant_id'], 'idx_task_watcher_tenant');
        $watcher->addIndex(['membership_id'], 'idx_task_watcher_membership');
        $watcher->addUniqueIndex(['task_id', 'membership_id'], 'uniq_task_watcher');
        $watcher->addForeignKeyConstraint('task', ['task_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_task_watcher_task');
        $watcher->addForeignKeyConstraint('membership', ['membership_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_task_watcher_membership');

        $label = $schema->createTable('label');
        $label->addColumn('id', Types::GUID);
        $label->addColumn('tenant_id', 'tenant_id');
        $label->addColumn('name', Types::STRING, ['length' => 60]);
        $label->addColumn('colour', Types::STRING, ['length' => 16]);
        $label->setPrimaryKey(['id']);
        $label->addIndex(['tenant_id'], 'idx_label_tenant');
        $label->addUniqueIndex(['tenant_id', 'name'], 'uniq_label_name');

        $taskLabel = $schema->createTable('task_label');
        $taskLabel->addColumn('id', Types::GUID);
        $taskLabel->addColumn('tenant_id', 'tenant_id');
        $taskLabel->addColumn('task_id', Types::GUID);
        $taskLabel->addColumn('label_id', Types::GUID);
        $taskLabel->setPrimaryKey(['id']);
        $taskLabel->addIndex(['tenant_id'], 'idx_task_label_tenant');
        $taskLabel->addIndex(['label_id'], 'idx_task_label_label');
        $taskLabel->addUniqueIndex(['task_id', 'label_id'], 'uniq_task_label');
        $taskLabel->addForeignKeyConstraint('task', ['task_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_task_label_task');
        $taskLabel->addForeignKeyConstraint('label', ['label_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_task_label_label');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('task_label');
        $schema->dropTable('label');
        $schema->dropTable('task_watcher');
        $schema->dropTable('task');
        $schema->dropTable('project_section');
        $schema->dropTable('project_status');
        $schema->dropTable('project');
    }
}
