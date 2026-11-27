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
 * The baseline of Work: its 12 tables as the global migrations left
 * them (ADR 0034). A database migrated before the module packages moves onto
 * it with app:migrations:rebase, which runs none of this. The time logged on
 * projects (time_entry) and the project's time settings were created here
 * until they moved to Timesheet, whose migrations create them where they are
 * missing; a database that ran this before keeps them.
 */
final class Version20261120000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the tables of Work';
    }

    public function up(Schema $schema): void
    {
        $this->commentMention($schema);
        $this->label($schema);
        $this->notification($schema);
        $this->notificationPreference($schema);
        $this->project($schema);
        $this->projectSection($schema);
        $this->projectStatus($schema);
        $this->task($schema);
        $this->taskComment($schema);
        $this->taskLabel($schema);
        $this->taskWatcher($schema);
        $this->workShare($schema);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('work_share');
        $schema->dropTable('task_watcher');
        $schema->dropTable('task_label');
        $schema->dropTable('task_comment');
        $schema->dropTable('task');
        $schema->dropTable('project_status');
        $schema->dropTable('project_section');
        $schema->dropTable('project');
        $schema->dropTable('notification_preference');
        $schema->dropTable('notification');
        $schema->dropTable('label');
        $schema->dropTable('comment_mention');
    }

    private function commentMention(Schema $schema): void
    {
        $table = $schema->createTable('comment_mention');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('comment_id', Types::GUID);
        $table->addColumn('membership_id', Types::GUID);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['comment_id'], 'idx_11713bddf8697d13');
        $table->addIndex(['membership_id'], 'idx_comment_mention_membership');
        $table->addUniqueIndex(['comment_id', 'membership_id'], 'uniq_comment_mention');
        $table->addForeignKeyConstraint('task_comment', ['comment_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_comment_mention_comment');
        $table->addForeignKeyConstraint('membership', ['membership_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_comment_mention_membership');
    }

    private function label(Schema $schema): void
    {
        $table = $schema->createTable('label');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('name', Types::STRING, ['length' => 60]);
        $table->addColumn('colour', Types::STRING, ['length' => 16]);
        $table->addColumn('live_name', Types::STRING, ['length' => 60, 'notnull' => false]);
        $table->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('deleted_by', Types::GUID, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['deleted_at'], 'idx_label_deleted');
        $table->addUniqueIndex(['live_name'], 'uniq_label_live_name');
    }

    private function notification(Schema $schema): void
    {
        $table = $schema->createTable('notification');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('recipient_id', Types::GUID);
        $table->addColumn('task_id', Types::GUID);
        $table->addColumn('kind', Types::STRING, ['length' => 16]);
        $table->addColumn('actor_id', Types::GUID);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('events', Types::INTEGER);
        $table->addColumn('direct_unread', Types::BOOLEAN);
        $table->addColumn('mentioned', Types::BOOLEAN);
        $table->addColumn('assigned', Types::BOOLEAN);
        $table->addColumn('read_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('done_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('emailed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['recipient_id'], 'idx_bf5476cae92f8f78');
        $table->addIndex(['actor_id'], 'idx_notification_actor');
        $table->addIndex(['recipient_id', 'done_at', 'created_at'], 'idx_notification_inbox');
        $table->addIndex(['task_id'], 'idx_notification_of_task');
        $table->addIndex(['recipient_id', 'task_id', 'done_at'], 'idx_notification_task');
        $table->addForeignKeyConstraint('membership', ['actor_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_notification_actor');
        $table->addForeignKeyConstraint('membership', ['recipient_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_notification_recipient');
        $table->addForeignKeyConstraint('task', ['task_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_notification_task');
    }

    private function notificationPreference(Schema $schema): void
    {
        $table = $schema->createTable('notification_preference');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('membership_id', Types::GUID);
        $table->addColumn('mentioned', Types::STRING, ['length' => 16]);
        $table->addColumn('assigned', Types::STRING, ['length' => 16]);
        $table->addColumn('commented', Types::STRING, ['length' => 16]);
        $table->addColumn('status_changed', Types::STRING, ['length' => 16]);
        $table->addColumn('shared', Types::STRING, ['length' => 16]);
        $table->addColumn('digest_hour', Types::SMALLINT);
        $table->addColumn('no_email', Types::BOOLEAN);
        $table->addColumn('last_digest_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['membership_id'], 'uniq_notification_preference_membership');
        $table->addForeignKeyConstraint('membership', ['membership_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_notification_preference_membership');
    }

    private function project(Schema $schema): void
    {
        $table = $schema->createTable('project');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('name', Types::STRING, ['length' => 200]);
        $table->addColumn('client_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('description', Types::TEXT);
        $table->addColumn('workflow_template', Types::STRING, ['length' => 16]);
        $table->addColumn('archived_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('created_by', Types::GUID);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('visibility', Types::STRING, ['length' => 16, 'default' => 'organisation']);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['client_id'], 'idx_project_client');
        $table->addForeignKeyConstraint('party', ['client_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_project_client');
    }

    private function projectSection(Schema $schema): void
    {
        $table = $schema->createTable('project_section');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('project_id', Types::GUID);
        $table->addColumn('name', Types::STRING, ['length' => 120]);
        $table->addColumn('position', Types::INTEGER);
        $table->addColumn('private', Types::BOOLEAN, ['default' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['project_id'], 'idx_bb6d768a166d1f9c');
        $table->addIndex(['project_id', 'position'], 'idx_project_section_position');
        $table->addForeignKeyConstraint('project', ['project_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_project_section_project');
    }

    private function projectStatus(Schema $schema): void
    {
        $table = $schema->createTable('project_status');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('project_id', Types::GUID);
        $table->addColumn('name', Types::STRING, ['length' => 80]);
        $table->addColumn('type', Types::STRING, ['length' => 16]);
        $table->addColumn('position', Types::INTEGER);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['project_id'], 'idx_6ca48e56166d1f9c');
        $table->addIndex(['project_id', 'position'], 'idx_project_status_position');
        $table->addForeignKeyConstraint('project', ['project_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_project_status_project');
    }

    private function task(Schema $schema): void
    {
        $table = $schema->createTable('task');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('project_id', Types::GUID);
        $table->addColumn('section_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('parent_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('title', Types::STRING, ['length' => 500]);
        $table->addColumn('description', Types::TEXT);
        $table->addColumn('status_id', Types::GUID);
        $table->addColumn('responsible_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('due_on', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('priority', Types::STRING, ['length' => 16]);
        $table->addColumn('position', Types::INTEGER);
        $table->addColumn('completed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('created_by', Types::GUID);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('deleted_by', Types::GUID, ['notnull' => false]);
        $table->addColumn('private', Types::BOOLEAN, ['default' => false]);
        $table->addColumn('estimate_minutes', Types::INTEGER, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['deleted_at'], 'idx_task_deleted');
        $table->addIndex(['parent_id'], 'idx_task_parent');
        $table->addIndex(['project_id'], 'idx_task_project');
        $table->addIndex(['project_id', 'position'], 'idx_task_project_position');
        $table->addIndex(['responsible_id'], 'idx_task_responsible');
        $table->addIndex(['responsible_id', 'due_on'], 'idx_task_responsible_due');
        $table->addIndex(['section_id'], 'idx_task_section');
        $table->addIndex(['status_id'], 'idx_task_status');
        $table->addForeignKeyConstraint('task', ['parent_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_task_parent');
        $table->addForeignKeyConstraint('project', ['project_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_task_project');
        $table->addForeignKeyConstraint('membership', ['responsible_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_task_responsible');
        $table->addForeignKeyConstraint('project_section', ['section_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_task_section');
        $table->addForeignKeyConstraint('project_status', ['status_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_task_status');
    }

    private function taskComment(Schema $schema): void
    {
        $table = $schema->createTable('task_comment');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('task_id', Types::GUID);
        $table->addColumn('author_id', Types::GUID);
        $table->addColumn('body', Types::TEXT);
        $table->addColumn('internal', Types::BOOLEAN);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('edited_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('deleted_by', Types::GUID, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['task_id'], 'idx_8b9578868db60186');
        $table->addIndex(['author_id'], 'idx_task_comment_author');
        $table->addIndex(['deleted_at'], 'idx_task_comment_deleted');
        $table->addIndex(['task_id', 'created_at'], 'idx_task_comment_task');
        $table->addForeignKeyConstraint('membership', ['author_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_task_comment_author');
        $table->addForeignKeyConstraint('task', ['task_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_task_comment_task');
    }

    private function taskLabel(Schema $schema): void
    {
        $table = $schema->createTable('task_label');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('task_id', Types::GUID);
        $table->addColumn('label_id', Types::GUID);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['task_id'], 'idx_c9034bc88db60186');
        $table->addIndex(['label_id'], 'idx_task_label_label');
        $table->addUniqueIndex(['task_id', 'label_id'], 'uniq_task_label');
        $table->addForeignKeyConstraint('label', ['label_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_task_label_label');
        $table->addForeignKeyConstraint('task', ['task_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_task_label_task');
    }

    private function taskWatcher(Schema $schema): void
    {
        $table = $schema->createTable('task_watcher');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('task_id', Types::GUID);
        $table->addColumn('membership_id', Types::GUID);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['task_id'], 'idx_ad6ecc468db60186');
        $table->addIndex(['membership_id'], 'idx_task_watcher_membership');
        $table->addUniqueIndex(['task_id', 'membership_id'], 'uniq_task_watcher');
        $table->addForeignKeyConstraint('membership', ['membership_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_task_watcher_membership');
        $table->addForeignKeyConstraint('task', ['task_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_task_watcher_task');
    }

    private function workShare(Schema $schema): void
    {
        $table = $schema->createTable('work_share');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('membership_id', Types::GUID);
        $table->addColumn('project_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('section_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('task_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['membership_id'], 'idx_c7fde6751fb354cd');
        $table->addIndex(['project_id'], 'idx_work_share_project');
        $table->addIndex(['section_id'], 'idx_work_share_section');
        $table->addIndex(['task_id'], 'idx_work_share_task');
        $table->addUniqueIndex(['membership_id', 'project_id'], 'uniq_work_share_project');
        $table->addUniqueIndex(['membership_id', 'section_id'], 'uniq_work_share_section');
        $table->addUniqueIndex(['membership_id', 'task_id'], 'uniq_work_share_task');
        $table->addForeignKeyConstraint('membership', ['membership_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_work_share_membership');
        $table->addForeignKeyConstraint('project', ['project_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_work_share_project');
        $table->addForeignKeyConstraint('project_section', ['section_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_work_share_section');
        $table->addForeignKeyConstraint('task', ['task_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_work_share_task');
    }
}
