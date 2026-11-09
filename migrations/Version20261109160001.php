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
 * Time tracking: time entries of a project, optionally of a task, the estimate
 * of a task and whether time on a project is billable by default.
 *
 * One timer runs per person: running_for holds the membership while an entry
 * runs and is null otherwise, and a plain unique index over it keeps a second
 * one out. A partial index would not do, as Doctrine cannot read one back from
 * SQLite and schema validation fails there (see Version20261101000003).
 *
 * An existing project is billable by default when it has a client, as a new
 * one is. That statement needs the column, so it runs in postUp(), after the
 * schema change, and --dry-run neither shows nor runs it.
 */
final class Version20261109160001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add time entries, task estimates and the billable default of projects';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('project')->addColumn('billable_by_default', Types::BOOLEAN, ['default' => false]);
        $schema->getTable('task')->addColumn('estimate_minutes', Types::INTEGER, ['notnull' => false]);

        $entry = $schema->createTable('time_entry');
        $entry->addColumn('id', Types::GUID);
        $entry->addColumn('tenant_id', 'tenant_id');
        $entry->addColumn('project_id', Types::GUID);
        $entry->addColumn('task_id', Types::GUID, ['notnull' => false]);
        $entry->addColumn('membership_id', Types::GUID);
        $entry->addColumn('worked_on', Types::DATE_IMMUTABLE);
        $entry->addColumn('minutes', Types::INTEGER, ['notnull' => false]);
        $entry->addColumn('started_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $entry->addColumn('ended_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $entry->addColumn('note', Types::TEXT);
        $entry->addColumn('billable', Types::BOOLEAN);
        $entry->addColumn('running_for', Types::GUID, ['notnull' => false]);
        $entry->addColumn('invoice_line_id', Types::GUID, ['notnull' => false]);
        $entry->addColumn('created_by', Types::GUID);
        $entry->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $entry->setPrimaryKey(['id']);
        $entry->addIndex(['tenant_id'], 'idx_time_entry_tenant');
        $entry->addIndex(['project_id', 'worked_on'], 'idx_time_entry_project');
        $entry->addIndex(['task_id'], 'idx_time_entry_task');
        $entry->addIndex(['membership_id', 'worked_on'], 'idx_time_entry_membership');
        $entry->addUniqueIndex(['running_for'], 'uniq_time_entry_running');
        $entry->addForeignKeyConstraint('project', ['project_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_time_entry_project');
        $entry->addForeignKeyConstraint('task', ['task_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_time_entry_task');
        $entry->addForeignKeyConstraint('membership', ['membership_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_time_entry_membership');
    }

    public function postUp(Schema $schema): void
    {
        // A dry run changes no schema, so the column is not there to fill.
        if (!\array_key_exists('billable_by_default', $this->connection->createSchemaManager()->listTableColumns('project'))) {
            return;
        }

        $this->connection->executeStatement('UPDATE project SET billable_by_default = (client_id IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('time_entry');
        $schema->getTable('task')->dropColumn('estimate_minutes');
        $schema->getTable('project')->dropColumn('billable_by_default');
    }
}
