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
 * A project's time budget: its dated changes, and which time of the project uses it.
 */
final class Version20261125120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the time budget of a project';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('project')->addColumn('budget_counting', Types::STRING, ['length' => 16, 'default' => 'all']);

        $table = $schema->createTable('project_budget_change');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('project_id', Types::GUID);
        $table->addColumn('effective_on', Types::DATE_IMMUTABLE);
        $table->addColumn('minutes', Types::INTEGER);
        $table->addColumn('note', Types::STRING, ['length' => 500]);
        $table->addColumn('source_activity_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('created_by', Types::GUID);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['project_id', 'effective_on'], 'idx_project_budget_change_project');
        $table->addForeignKeyConstraint('project', ['project_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_project_budget_change_project');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('project_budget_change');
        $schema->getTable('project')->dropColumn('budget_counting');
    }
}
