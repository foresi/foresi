<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Import\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The baseline of Import: its 5 tables as the global migrations left
 * them (ADR 0034). A database migrated before the module packages moves onto
 * it with app:migrations:rebase, which runs none of this.
 */
final class Version20261120000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the tables of Import';
    }

    public function up(Schema $schema): void
    {
        $this->import($schema);
        $this->importAmbiguousMatch($schema);
        $this->importMissingRecord($schema);
        $this->importProfile($schema);
        $this->importRow($schema);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('import_row');
        $schema->dropTable('import_profile');
        $schema->dropTable('import_missing_record');
        $schema->dropTable('import_ambiguous_match');
        $schema->dropTable('import');
    }

    private function import(Schema $schema): void
    {
        $table = $schema->createTable('import');
        $table->addColumn('headers', Types::JSON);
        $table->addColumn('mapping', Types::JSON);
        $table->addColumn('option_mode', Types::STRING, ['length' => 32]);
        $table->addColumn('option_scope', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('option_match_key', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('option_blank_cells', Types::STRING, ['length' => 16]);
        $table->addColumn('option_date_order', Types::STRING, ['length' => 16]);
        $table->addColumn('option_decimal_separator', Types::STRING, ['length' => 16]);
        $table->addColumn('option_archive_confirmation', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('state', Types::STRING, ['length' => 32]);
        $table->addColumn('total', Types::INTEGER);
        $table->addColumn('processed', Types::INTEGER);
        $table->addColumn('created', Types::INTEGER);
        $table->addColumn('updated', Types::INTEGER);
        $table->addColumn('restored', Types::INTEGER);
        $table->addColumn('skipped', Types::INTEGER);
        $table->addColumn('archived', Types::INTEGER);
        $table->addColumn('failed', Types::INTEGER);
        $table->addColumn('chunks', Types::INTEGER);
        $table->addColumn('chunks_done', Types::INTEGER);
        $table->addColumn('archive_candidates', Types::INTEGER);
        $table->addColumn('started_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('finished_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('seen_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('failure_message_key', Types::STRING, ['length' => 128, 'notnull' => false]);
        $table->addColumn('target_key', Types::STRING, ['length' => 64]);
        $table->addColumn('file_key', Types::STRING, ['length' => 100, 'notnull' => false]);
        $table->addColumn('original_name', Types::STRING, ['length' => 255]);
        $table->addColumn('source_settings', Types::JSON);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('proposals', Types::JSON, ['default' => '[]']);
        $table->addColumn('preset_key', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('reverted', Types::INTEGER, ['default' => 0]);
        $table->addColumn('changed_after_import', Types::INTEGER, ['default' => 0]);
        $table->addColumn('unidentified', Types::INTEGER, ['default' => 0]);
        $table->addColumn('progressed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('id', Types::GUID);
        $table->addColumn('profile_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('actor_user_id', Types::GUID);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['profile_id'], 'idx_9d4ece1dccfa12b8');
        $table->addIndex(['state', 'finished_at'], 'idx_import_finished');
        $table->addForeignKeyConstraint('import_profile', ['profile_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_import_profile');
    }

    private function importAmbiguousMatch(Schema $schema): void
    {
        $table = $schema->createTable('import_ambiguous_match');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('import_id', Types::GUID);
        $table->addColumn('record_id', Types::GUID);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['import_id'], 'idx_7c7b4c22b6a263d9');
        $table->addIndex(['import_id', 'record_id'], 'idx_import_ambiguous_record');
        $table->addForeignKeyConstraint('import', ['import_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_import_ambiguous_match_import');
    }

    private function importMissingRecord(Schema $schema): void
    {
        $table = $schema->createTable('import_missing_record');
        $table->addColumn('outcome', Types::STRING, ['length' => 32]);
        $table->addColumn('before', Types::JSON, ['notnull' => false]);
        $table->addColumn('processed_in_run', Types::BOOLEAN);
        $table->addColumn('processed_in_undo', Types::BOOLEAN);
        $table->addColumn('chunk', Types::INTEGER);
        $table->addColumn('id', Types::GUID);
        $table->addColumn('import_id', Types::GUID);
        $table->addColumn('record_id', Types::GUID);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['import_id'], 'idx_13a7a901b6a263d9');
        $table->addIndex(['import_id', 'chunk'], 'idx_import_missing_chunk');
        $table->addUniqueIndex(['import_id', 'record_id'], 'uniq_import_missing_record');
        $table->addForeignKeyConstraint('import', ['import_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_import_missing_record_import');
    }

    private function importProfile(Schema $schema): void
    {
        $table = $schema->createTable('import_profile');
        $table->addColumn('mapping', Types::JSON);
        $table->addColumn('option_mode', Types::STRING, ['length' => 32]);
        $table->addColumn('option_scope', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('option_match_key', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('option_blank_cells', Types::STRING, ['length' => 16]);
        $table->addColumn('option_date_order', Types::STRING, ['length' => 16]);
        $table->addColumn('option_decimal_separator', Types::STRING, ['length' => 16]);
        $table->addColumn('option_archive_confirmation', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('name', Types::STRING, ['length' => 255]);
        $table->addColumn('target_key', Types::STRING, ['length' => 64]);
        $table->addColumn('preset_key', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('header_signature', Types::STRING, ['length' => 40, 'fixed' => true]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('id', Types::GUID);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['target_key', 'header_signature'], 'idx_import_profile_signature');
    }

    private function importRow(Schema $schema): void
    {
        $table = $schema->createTable('import_row');
        $table->addColumn('outcome', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('errors', Types::JSON);
        $table->addColumn('before', Types::JSON, ['notnull' => false]);
        $table->addColumn('processed_in_run', Types::BOOLEAN);
        $table->addColumn('processed_in_undo', Types::BOOLEAN);
        $table->addColumn('row_number', Types::INTEGER);
        $table->addColumn('chunk', Types::INTEGER);
        $table->addColumn('raw', Types::JSON);
        $table->addColumn('match_value', Types::STRING, ['length' => 40, 'fixed' => true, 'notnull' => false]);
        $table->addColumn('id', Types::GUID);
        $table->addColumn('import_id', Types::GUID);
        $table->addColumn('record_id', Types::GUID, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['import_id'], 'idx_105d565bb6a263d9');
        $table->addIndex(['import_id', 'chunk'], 'idx_import_row_chunk');
        $table->addIndex(['import_id', 'match_value'], 'idx_import_row_match');
        $table->addIndex(['import_id', 'record_id'], 'idx_import_row_record');
        $table->addUniqueIndex(['import_id', 'row_number'], 'uniq_import_row_number');
        $table->addForeignKeyConstraint('import', ['import_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_import_row_import');
    }
}
