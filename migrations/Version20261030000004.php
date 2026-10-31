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
 * Adds what the import pipeline keeps between its steps: the column proposals
 * and recognised preset, the undo counters, the match key fingerprint of a
 * staged row and the records a full replace archives. Built with the schema
 * API so it runs unchanged on SQLite and PostgreSQL.
 */
final class Version20261030000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the import pipeline columns and the import_missing_record table';
    }

    public function up(Schema $schema): void
    {
        $import = $schema->getTable('import');
        $import->addColumn('proposals', Types::JSON, ['default' => '[]']);
        $import->addColumn('preset_key', Types::STRING, ['length' => 64, 'notnull' => false]);
        $import->addColumn('reverted', Types::INTEGER, ['default' => 0]);
        $import->addColumn('changed_after_import', Types::INTEGER, ['default' => 0]);

        $row = $schema->getTable('import_row');
        $row->addColumn('match_value', Types::STRING, ['length' => 40, 'fixed' => true, 'notnull' => false]);
        $row->addIndex(['import_id', 'match_value'], 'idx_import_row_match');
        $row->addIndex(['import_id', 'record_id'], 'idx_import_row_record');

        $missing = $schema->createTable('import_missing_record');
        $missing->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $missing->addColumn('tenant_id', 'tenant_id');
        $missing->addColumn('outcome', Types::STRING, ['length' => 32]);
        $missing->addColumn('before', Types::JSON, ['notnull' => false]);
        $missing->addColumn('processed_in_run', Types::BOOLEAN);
        $missing->addColumn('processed_in_undo', Types::BOOLEAN);
        $missing->addColumn('import_id', Types::INTEGER);
        $missing->addColumn('chunk', Types::INTEGER);
        $missing->addColumn('record_id', Types::INTEGER);
        $missing->setPrimaryKey(['id']);
        $missing->addIndex(['tenant_id'], 'idx_import_missing_tenant');
        $missing->addIndex(['import_id', 'chunk'], 'idx_import_missing_chunk');
        $missing->addUniqueIndex(['import_id', 'record_id'], 'uniq_import_missing_record');
        $missing->addForeignKeyConstraint('import', ['import_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_import_missing_record_import');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('import_missing_record');

        $row = $schema->getTable('import_row');
        $row->dropIndex('idx_import_row_record');
        $row->dropIndex('idx_import_row_match');
        $row->dropColumn('match_value');

        $import = $schema->getTable('import');
        foreach (['changed_after_import', 'reverted', 'preset_key', 'proposals'] as $column) {
            $import->dropColumn($column);
        }
    }
}
