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
 * The trash of tasks: when a task was put there and by whom. No task is in the
 * trash yet, so the columns start empty.
 */
final class Version20261108140001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the trash of tasks';
    }

    public function up(Schema $schema): void
    {
        $task = $schema->getTable('task');
        $task->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $task->addColumn('deleted_by', Types::GUID, ['notnull' => false]);
        $task->addIndex(['tenant_id', 'deleted_at'], 'idx_task_deleted');
    }

    public function down(Schema $schema): void
    {
        $task = $schema->getTable('task');
        $task->dropIndex('idx_task_deleted');
        $task->dropColumn('deleted_by');
        $task->dropColumn('deleted_at');
    }
}
