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
 * Adds the optional correlation id that groups audit entries of one unit of
 * work. Built with the schema API so it runs unchanged on SQLite and
 * PostgreSQL.
 */
final class Version20261030000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add correlation_id to audit_entry';
    }

    public function up(Schema $schema): void
    {
        $entry = $schema->getTable('audit_entry');
        $entry->addColumn('correlation_id', Types::STRING, ['length' => 64, 'notnull' => false]);
        $entry->addIndex(['tenant_id', 'correlation_id'], 'idx_audit_entry_correlation');
    }

    public function down(Schema $schema): void
    {
        $entry = $schema->getTable('audit_entry');
        $entry->dropIndex('idx_audit_entry_correlation');
        $entry->dropColumn('correlation_id');
    }
}
