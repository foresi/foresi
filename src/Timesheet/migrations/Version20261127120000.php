<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Migrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Time tracking moves from Work to Timesheet: the time logged (time_entry) and
 * the time budget of a project (project_budget_change) are created here where
 * they are missing. A fresh install gets them here; a database whose Work
 * migrations created them keeps them, rows and all. The project's time
 * settings get tables of their own, keyed by the project: its hourly rate and
 * which time uses its budget (project_time_setting), and whether its time is
 * billable by default, a custom field of the project (project_time_field).
 * Where the project still holds them, they are copied over;
 * Version20261127120001 then takes them off the project.
 *
 * Going down drops the four tables, time_entry and project_budget_change too,
 * as on a fresh install; the settings go back onto the project first.
 */
final class Version20261127120000 extends AbstractMigration
{
    /** The columns of project the settings are copied from, where it still has them. */
    private const array PROJECT_COLUMNS = ['billable_by_default', 'hourly_rate_minor', 'hourly_rate_currency', 'budget_counting'];

    /** @var list<string> */
    private array $projectColumns = [];

    public function getDescription(): string
    {
        return 'Create the time and the time budget of projects in Timesheet, and the time settings of a project';
    }

    public function up(Schema $schema): void
    {
        // Work's migrations create the project before this runs; a reader of the migrations alone may not have it.
        $project = $schema->hasTable('project') ? $schema->getTable('project') : null;
        $this->projectColumns = $project instanceof \Doctrine\DBAL\Schema\Table ? array_values(array_filter(self::PROJECT_COLUMNS, $project->hasColumn(...))) : [];
        if (!$schema->hasTable('time_entry')) {
            $this->timeEntry($schema);
        }
        if (!$schema->hasTable('project_budget_change')) {
            $this->projectBudgetChange($schema);
        }

        $table = $schema->createTable('project_time_setting');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('project_id', Types::GUID);
        $table->addColumn('hourly_rate_minor', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('hourly_rate_currency', Types::STRING, ['length' => 3, 'fixed' => true, 'notnull' => false]);
        $table->addColumn('budget_counting', Types::STRING, ['length' => 16, 'default' => 'all']);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['project_id'], 'uniq_e154fd9d166d1f9c');
        $table->addForeignKeyConstraint('project', ['project_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_project_time_setting_project');

        $table = $schema->createTable('project_time_field');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('billable_by_default', Types::BOOLEAN, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
    }

    public function postUp(Schema $schema): void
    {
        // The rows are copied once the tables exist, the same on SQLite and PostgreSQL; a
        // setting copied from a project takes the project's id as its own, a UUID as unique.
        if (\in_array('billable_by_default', $this->projectColumns, true)) {
            $this->connection->executeStatement(
                'INSERT INTO project_time_field (id, billable_by_default) SELECT id, billable_by_default FROM project WHERE billable_by_default = ?',
                [true],
                [ParameterType::BOOLEAN],
            );
        }
        $rate = \in_array('hourly_rate_minor', $this->projectColumns, true);
        $counting = \in_array('budget_counting', $this->projectColumns, true);
        if ($rate || $counting) {
            $this->connection->executeStatement(\sprintf(
                'INSERT INTO project_time_setting (id, project_id, hourly_rate_minor, hourly_rate_currency, budget_counting) SELECT id, id, %s, %s, %s FROM project WHERE %s',
                $rate ? 'hourly_rate_minor' : 'NULL',
                $rate ? 'hourly_rate_currency' : 'NULL',
                $counting ? 'budget_counting' : "'all'",
                implode(' OR ', [...($rate ? ['hourly_rate_minor IS NOT NULL'] : []), ...($counting ? ["budget_counting <> 'all'"] : [])]),
            ));
        }
    }

    public function preDown(Schema $schema): void
    {
        // Back onto the project, where Version20261127120001 going down gave it the columns again.
        if (!$schema->hasTable('project') || !$schema->getTable('project')->hasColumn('billable_by_default')) {
            return;
        }
        foreach ($this->connection->fetchFirstColumn('SELECT id FROM project_time_field WHERE billable_by_default = ?', [true], [ParameterType::BOOLEAN]) as $id) {
            $this->connection->executeStatement('UPDATE project SET billable_by_default = ? WHERE id = ?', [true, $id], [ParameterType::BOOLEAN, ParameterType::STRING]);
        }
        foreach ($this->connection->fetchAllAssociative('SELECT project_id, hourly_rate_minor, hourly_rate_currency, budget_counting FROM project_time_setting') as $row) {
            $this->connection->update('project', [
                'hourly_rate_minor' => $row['hourly_rate_minor'],
                'hourly_rate_currency' => $row['hourly_rate_currency'],
                'budget_counting' => $row['budget_counting'],
            ], ['id' => $row['project_id']]);
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('project_time_field');
        $schema->dropTable('project_time_setting');
        $schema->dropTable('project_budget_change');
        $schema->dropTable('time_entry');
    }

    private function timeEntry(Schema $schema): void
    {
        $table = $schema->createTable('time_entry');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('project_id', Types::GUID);
        $table->addColumn('task_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('membership_id', Types::GUID);
        $table->addColumn('worked_on', Types::DATE_IMMUTABLE);
        $table->addColumn('minutes', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('started_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('ended_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('note', Types::TEXT);
        $table->addColumn('billable', Types::BOOLEAN);
        $table->addColumn('running_for', Types::GUID, ['notnull' => false]);
        $table->addColumn('invoice_line_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('created_by', Types::GUID);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('invoice_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('invoiced_rate_minor', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('invoiced_rate_currency', Types::STRING, ['length' => 3, 'fixed' => true, 'notnull' => false]);
        $table->addColumn('invoiced_minutes', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('deleted_by', Types::GUID, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['project_id'], 'idx_6e537c0c166d1f9c');
        $table->addIndex(['membership_id'], 'idx_6e537c0c1fb354cd');
        $table->addIndex(['project_id', 'deleted_at'], 'idx_time_entry_deleted');
        $table->addIndex(['invoice_id'], 'idx_time_entry_invoice');
        $table->addIndex(['membership_id', 'worked_on'], 'idx_time_entry_membership');
        $table->addIndex(['project_id', 'worked_on'], 'idx_time_entry_project');
        $table->addIndex(['task_id'], 'idx_time_entry_task');
        $table->addUniqueIndex(['running_for'], 'uniq_time_entry_running');
        $table->addForeignKeyConstraint('membership', ['membership_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_time_entry_membership');
        $table->addForeignKeyConstraint('project', ['project_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_time_entry_project');
        $table->addForeignKeyConstraint('task', ['task_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_time_entry_task');
    }

    private function projectBudgetChange(Schema $schema): void
    {
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
}
