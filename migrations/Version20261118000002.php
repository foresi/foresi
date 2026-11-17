<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One instance serves one customer (ADR 0035): the tables of the webhooks, the
 * event log, the audit log and the attachments lose their tenant column. The
 * guard (Version20261117210001) made sure the database holds at most one
 * tenant. The indexes that led with the tenant are rebuilt on their remaining
 * columns under the same names, except the one of the active webhook
 * endpoints, whose name spoke of the tenant; the event log's index of the
 * tenant and position goes, as the unique index of the position serves the
 * feed. The plain tenant indexes go with the column.
 *
 * Going down adds the column back, not null, so it works only while these
 * tables are empty. With data, restore the dump taken before this migration.
 */
final class Version20261118000002 extends AbstractMigration
{
    /** Each table with its plain tenant index, if it has one. */
    private const array TENANT_INDEXES = [
        'webhook_endpoint' => null,
        'webhook_delivery' => null,
        'webhook_delivery_attempt' => null,
        'event_log' => null,
        'audit_entry' => 'idx_audit_entry_tenant',
        'attachment' => 'idx_attachment_tenant',
    ];

    /** The indexes that led with the tenant: table, name before, columns after the tenant, name after (null: dropped). */
    private const array LED_BY_TENANT = [
        ['webhook_endpoint', 'idx_webhook_endpoint_tenant_active', ['active'], 'idx_webhook_endpoint_active'],
        ['event_log', 'idx_event_log_tenant_position', ['position'], null],
        ['audit_entry', 'idx_audit_entry_entity', ['entity_class', 'entity_id'], 'idx_audit_entry_entity'],
        ['audit_entry', 'idx_audit_entry_root', ['root_class', 'root_id'], 'idx_audit_entry_root'],
        ['audit_entry', 'idx_audit_entry_correlation', ['correlation_id'], 'idx_audit_entry_correlation'],
        ['attachment', 'idx_attachment_owner', ['owner_kind', 'owner_id'], 'idx_attachment_owner'],
    ];

    public function getDescription(): string
    {
        return 'Drop the tenant column from the webhook, event log, audit log and attachment tables';
    }

    public function up(Schema $schema): void
    {
        foreach (self::LED_BY_TENANT as [$table, $before]) {
            $schema->getTable($table)->dropIndex($before);
        }
        foreach (self::TENANT_INDEXES as $table => $index) {
            if (null !== $index) {
                $schema->getTable($table)->dropIndex($index);
            }
            $schema->getTable($table)->dropColumn('tenant_id');
        }
        foreach (self::LED_BY_TENANT as [$table, , $columns, $after]) {
            if (null !== $after) {
                $schema->getTable($table)->addIndex($columns, $after);
            }
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
        foreach (self::LED_BY_TENANT as [$table, , , $after]) {
            if (null !== $after) {
                $schema->getTable($table)->dropIndex($after);
            }
        }
        foreach (self::TENANT_INDEXES as $table => $index) {
            $schema->getTable($table)->addColumn('tenant_id', 'tenant_id', ['notnull' => true]);
            if (null !== $index) {
                $schema->getTable($table)->addIndex(['tenant_id'], $index);
            }
        }
        foreach (self::LED_BY_TENANT as [$table, $before, $columns]) {
            $schema->getTable($table)->addIndex(['tenant_id', ...$columns], $before);
        }
    }
}
