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
 * The tasks each person starred for My priorities, in their own order (plan 37.2).
 */
final class Version20261126120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the starred tasks of each person';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('task_star');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('task_id', Types::GUID);
        $table->addColumn('membership_id', Types::GUID);
        $table->addColumn('position', Types::INTEGER);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['task_id', 'membership_id'], 'uniq_task_star');
        $table->addIndex(['membership_id', 'position'], 'idx_task_star_membership');
        $table->addForeignKeyConstraint('task', ['task_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_task_star_task');
        $table->addForeignKeyConstraint('membership', ['membership_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_task_star_membership');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('task_star');
    }
}
