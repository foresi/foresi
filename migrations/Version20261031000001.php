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
 * Keeps the records an ambiguous row matched, so a full replace never archives
 * them, and counts the rows whose match key could not be read. Built with the
 * schema API so it runs unchanged on SQLite and PostgreSQL.
 */
final class Version20261031000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add import_ambiguous_match and import.unidentified';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('import')->addColumn('unidentified', Types::INTEGER, ['default' => 0]);

        $match = $schema->createTable('import_ambiguous_match');
        $match->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $match->addColumn('tenant_id', 'tenant_id');
        $match->addColumn('import_id', Types::INTEGER);
        $match->addColumn('record_id', Types::INTEGER);
        $match->setPrimaryKey(['id']);
        $match->addIndex(['tenant_id'], 'idx_import_ambiguous_tenant');
        $match->addIndex(['import_id', 'record_id'], 'idx_import_ambiguous_record');
        $match->addForeignKeyConstraint('import', ['import_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_import_ambiguous_match_import');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('import_ambiguous_match');
        $schema->getTable('import')->dropColumn('unidentified');
    }
}
