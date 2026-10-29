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
 * Creates the append-only audit_entry table. Built with the schema API (not raw
 * SQL) so it runs unchanged on SQLite and PostgreSQL; the changes column is
 * the portable JSON type.
 */
final class Version20261029000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the audit_entry table';
    }

    public function up(Schema $schema): void
    {
        $entry = $schema->createTable('audit_entry');
        $entry->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $entry->addColumn('tenant_id', 'tenant_id');
        $entry->addColumn('entity_class', Types::STRING, ['length' => 255]);
        $entry->addColumn('entity_id', Types::STRING, ['length' => 64]);
        $entry->addColumn('action', Types::STRING, ['length' => 16]);
        $entry->addColumn('changes', Types::JSON);
        $entry->addColumn('user_id', Types::INTEGER, ['notnull' => false]);
        $entry->addColumn('occurred_at', Types::DATETIME_IMMUTABLE);
        $entry->setPrimaryKey(['id']);
        $entry->addIndex(['tenant_id'], 'idx_audit_entry_tenant');
        $entry->addIndex(['tenant_id', 'entity_class', 'entity_id'], 'idx_audit_entry_entity');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('audit_entry');
    }
}
