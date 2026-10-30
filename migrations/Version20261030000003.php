<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the import, import profile and staged row tables. Built with the
 * schema API so it runs unchanged on SQLite and PostgreSQL.
 */
final class Version20261030000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the import_profile, import and import_row tables';
    }

    public function up(Schema $schema): void
    {
        $profile = $schema->createTable('import_profile');
        $profile->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $profile->addColumn('tenant_id', 'tenant_id');
        $profile->addColumn('mapping', Types::JSON);
        $this->addOptions($profile);
        $profile->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $profile->addColumn('name', Types::STRING, ['length' => 255]);
        $profile->addColumn('target_key', Types::STRING, ['length' => 64]);
        $profile->addColumn('preset_key', Types::STRING, ['length' => 64, 'notnull' => false]);
        $profile->addColumn('header_signature', Types::STRING, ['length' => 40, 'fixed' => true]);
        $profile->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $profile->setPrimaryKey(['id']);
        $profile->addIndex(['tenant_id'], 'idx_import_profile_tenant');
        $profile->addIndex(['tenant_id', 'target_key', 'header_signature'], 'idx_import_profile_signature');

        $import = $schema->createTable('import');
        $import->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $import->addColumn('tenant_id', 'tenant_id');
        $import->addColumn('profile_id', Types::INTEGER, ['notnull' => false]);
        $import->addColumn('headers', Types::JSON);
        $import->addColumn('mapping', Types::JSON);
        $this->addOptions($import);
        $import->addColumn('state', Types::STRING, ['length' => 32]);
        foreach (['total', 'processed', 'created', 'updated', 'restored', 'skipped', 'archived', 'failed', 'chunks', 'chunks_done', 'archive_candidates'] as $counter) {
            $import->addColumn($counter, Types::INTEGER);
        }
        $import->addColumn('started_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $import->addColumn('finished_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $import->addColumn('seen_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $import->addColumn('failure_message_key', Types::STRING, ['length' => 128, 'notnull' => false]);
        $import->addColumn('target_key', Types::STRING, ['length' => 64]);
        $import->addColumn('file_key', Types::STRING, ['length' => 100, 'notnull' => false]);
        $import->addColumn('original_name', Types::STRING, ['length' => 255]);
        $import->addColumn('source_settings', Types::JSON);
        $import->addColumn('actor_user_id', Types::INTEGER);
        $import->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $import->setPrimaryKey(['id']);
        $import->addIndex(['tenant_id'], 'idx_import_tenant');
        $import->addIndex(['state', 'finished_at'], 'idx_import_finished');
        $import->addIndex(['profile_id'], 'IDX_9D4ECE1DCCFA12B8');
        $import->addForeignKeyConstraint('import_profile', ['profile_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_import_profile');

        $row = $schema->createTable('import_row');
        $row->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $row->addColumn('tenant_id', 'tenant_id');
        $row->addColumn('import_id', Types::INTEGER);
        $row->addColumn('outcome', Types::STRING, ['length' => 32, 'notnull' => false]);
        $row->addColumn('errors', Types::JSON);
        $row->addColumn('record_id', Types::INTEGER, ['notnull' => false]);
        $row->addColumn('before', Types::JSON, ['notnull' => false]);
        $row->addColumn('processed_in_run', Types::BOOLEAN);
        $row->addColumn('processed_in_undo', Types::BOOLEAN);
        $row->addColumn('row_number', Types::INTEGER);
        $row->addColumn('chunk', Types::INTEGER);
        $row->addColumn('raw', Types::JSON);
        $row->setPrimaryKey(['id']);
        $row->addIndex(['tenant_id'], 'idx_import_row_tenant');
        $row->addIndex(['import_id', 'chunk'], 'idx_import_row_chunk');
        $row->addUniqueIndex(['import_id', 'row_number'], 'uniq_import_row_number');
        $row->addForeignKeyConstraint('import', ['import_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_import_row_import');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('import_row');
        $schema->dropTable('import');
        $schema->dropTable('import_profile');
    }

    private function addOptions(Table $table): void
    {
        $table->addColumn('option_mode', Types::STRING, ['length' => 32]);
        $table->addColumn('option_scope', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('option_match_key', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('option_blank_cells', Types::STRING, ['length' => 16]);
        $table->addColumn('option_date_order', Types::STRING, ['length' => 16]);
        $table->addColumn('option_decimal_separator', Types::STRING, ['length' => 16]);
        $table->addColumn('option_archive_confirmation', Types::INTEGER, ['notnull' => false]);
    }
}
