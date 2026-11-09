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
 * The inbox of notifications: one open row per person and task, holding the
 * latest event, read, done and mailed moments.
 */
final class Version20261109130001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add notifications';
    }

    public function up(Schema $schema): void
    {
        $notification = $schema->createTable('notification');
        $notification->addColumn('id', Types::GUID);
        $notification->addColumn('tenant_id', 'tenant_id');
        $notification->addColumn('recipient_id', Types::GUID);
        $notification->addColumn('task_id', Types::GUID);
        $notification->addColumn('kind', Types::STRING, ['length' => 16]);
        $notification->addColumn('actor_id', Types::GUID);
        $notification->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $notification->addColumn('events', Types::INTEGER);
        $notification->addColumn('direct_unread', Types::BOOLEAN);
        $notification->addColumn('mentioned', Types::BOOLEAN);
        $notification->addColumn('assigned', Types::BOOLEAN);
        $notification->addColumn('read_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $notification->addColumn('done_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $notification->addColumn('emailed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $notification->setPrimaryKey(['id']);
        $notification->addIndex(['tenant_id'], 'idx_notification_tenant');
        $notification->addIndex(['recipient_id', 'done_at', 'created_at'], 'idx_notification_inbox');
        $notification->addIndex(['recipient_id', 'task_id', 'done_at'], 'idx_notification_task');
        $notification->addIndex(['task_id'], 'idx_notification_of_task');
        $notification->addIndex(['actor_id'], 'idx_notification_actor');
        $notification->addForeignKeyConstraint('membership', ['recipient_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_notification_recipient');
        $notification->addForeignKeyConstraint('task', ['task_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_notification_task');
        $notification->addForeignKeyConstraint('membership', ['actor_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_notification_actor');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('notification');
    }
}
