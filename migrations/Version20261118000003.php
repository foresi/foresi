<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One instance serves one customer (ADR 0035): the tables of banking and work
 * lose their tenant column. The guard (Version20261117210001) made sure the
 * database holds at most one tenant, so no rows of two tenants meet in a unique
 * index. The indexes that led with the tenant are rebuilt on their remaining
 * columns under the same names; the plain tenant indexes go with the column.
 *
 * Going down adds the column back, not null, so it works only while these
 * tables are empty. With data, restore the dump taken before this migration.
 */
final class Version20261118000003 extends AbstractMigration
{
    /** Each table with its plain tenant index. */
    private const array TENANT_INDEXES = [
        'bank_feed' => 'idx_bank_feed_tenant',
        'bank_movement' => 'idx_bank_movement_tenant',
        'firm_bank_pairing' => 'idx_firm_bank_pairing_tenant',
        'inbound_message' => 'idx_inbound_message_tenant',
        'comment_mention' => 'idx_comment_mention_tenant',
        'label' => 'idx_label_tenant',
        'notification' => 'idx_notification_tenant',
        'notification_preference' => 'idx_notification_preference_tenant',
        'project' => 'idx_project_tenant',
        'project_section' => 'idx_project_section_tenant',
        'project_status' => 'idx_project_status_tenant',
        'task' => 'idx_task_tenant',
        'task_comment' => 'idx_task_comment_tenant',
        'task_label' => 'idx_task_label_tenant',
        'task_watcher' => 'idx_task_watcher_tenant',
        'time_entry' => 'idx_time_entry_tenant',
        'work_share' => 'idx_work_share_tenant',
    ];

    /** The indexes that led with the tenant: table, name, the columns after the tenant, unique. */
    private const array LED_BY_TENANT = [
        ['inbound_message', 'uniq_inbound_message_dedup', ['dedup_key'], true],
        ['label', 'uniq_label_name', ['name'], true],
        ['task', 'idx_task_project_position', ['project_id', 'position'], false],
        ['task', 'idx_task_responsible_due', ['responsible_id', 'due_on'], false],
        ['task', 'idx_task_deleted', ['deleted_at'], false],
    ];

    public function getDescription(): string
    {
        return 'Drop the tenant column from the banking and work tables';
    }

    public function up(Schema $schema): void
    {
        foreach (self::LED_BY_TENANT as [$table, $name]) {
            $schema->getTable($table)->dropIndex($name);
        }
        foreach (self::TENANT_INDEXES as $table => $index) {
            $schema->getTable($table)->dropIndex($index);
            $schema->getTable($table)->dropColumn('tenant_id');
        }
        foreach (self::LED_BY_TENANT as [$table, $name, $columns, $unique]) {
            $unique
                ? $schema->getTable($table)->addUniqueIndex($columns, $name)
                : $schema->getTable($table)->addIndex($columns, $name);
        }
    }

    public function preDown(Schema $schema): void
    {
        foreach (array_keys(self::TENANT_INDEXES) as $table) {
            $this->abortIf(
                false !== $this->connection->fetchOne('SELECT 1 FROM '.$table),
                \sprintf('The table %s holds rows, which have no tenant to go back to: restore the dump taken before this migration instead.', $table),
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::LED_BY_TENANT as [$table, $name]) {
            $schema->getTable($table)->dropIndex($name);
        }
        foreach (self::TENANT_INDEXES as $table => $index) {
            $schema->getTable($table)->addColumn('tenant_id', 'tenant_id', ['notnull' => true]);
            $schema->getTable($table)->addIndex(['tenant_id'], $index);
        }
        foreach (self::LED_BY_TENANT as [$table, $name, $columns, $unique]) {
            $unique
                ? $schema->getTable($table)->addUniqueIndex(['tenant_id', ...$columns], $name)
                : $schema->getTable($table)->addIndex(['tenant_id', ...$columns], $name);
        }
    }
}
