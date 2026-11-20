<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Idoklad\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The baseline of iDoklad: its 6 tables as the global migrations left
 * them (ADR 0034). A database migrated before the module packages moves onto
 * it with app:migrations:rebase, which runs none of this.
 */
final class Version20261120000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the tables of iDoklad';
    }

    public function up(Schema $schema): void
    {
        $this->idokladConnection($schema);
        $this->idokladImportRun($schema);
        $this->idokladJob($schema);
        $this->idokladLink($schema);
        $this->idokladPull($schema);
        $this->idokladRecord($schema);
    }

    /**
     * A table of one row keeps it so: the unique one_row column, and on
     * PostgreSQL a check that it is true. SQLite cannot add a check to a
     * table, so there the unique index alone stops a second row.
     */
    public function postUp(Schema $schema): void
    {
        // A dry run creates no table to check.
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform
            || !$this->connection->createSchemaManager()->tablesExist(['idoklad_connection'])) {
            return;
        }
        $this->connection->executeStatement('ALTER TABLE idoklad_connection ADD CONSTRAINT chk_idoklad_connection_one_row CHECK (one_row)');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('idoklad_record');
        $schema->dropTable('idoklad_pull');
        $schema->dropTable('idoklad_link');
        $schema->dropTable('idoklad_job');
        $schema->dropTable('idoklad_import_run');
        $schema->dropTable('idoklad_connection');
    }

    private function idokladConnection(Schema $schema): void
    {
        $table = $schema->createTable('idoklad_connection');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('client_id', Types::STRING, ['length' => 255]);
        $table->addColumn('secret_sealed', Types::TEXT);
        $table->addColumn('one_row', Types::BOOLEAN, ['default' => true]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['one_row'], 'uniq_idoklad_connection_one_row');
    }

    private function idokladImportRun(Schema $schema): void
    {
        $table = $schema->createTable('idoklad_import_run');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('mode', Types::STRING, ['length' => 8]);
        $table->addColumn('pull_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('started_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('finished_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('counts', Types::JSON);
        $table->addColumn('report', Types::JSON);
        $table->addColumn('error', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('undone_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('options', Types::JSON, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['started_at'], 'idx_idoklad_import_run_started');
    }

    private function idokladJob(Schema $schema): void
    {
        $table = $schema->createTable('idoklad_job');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('kind', Types::STRING, ['length' => 8]);
        $table->addColumn('state', Types::STRING, ['length' => 8]);
        $table->addColumn('requested_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('started_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('finished_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('remind_from', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('result_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('error', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('options', Types::JSON, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['requested_at'], 'idx_idoklad_job_requested');
    }

    private function idokladLink(Schema $schema): void
    {
        $table = $schema->createTable('idoklad_link');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('resource', Types::STRING, ['length' => 32]);
        $table->addColumn('external_id', Types::INTEGER);
        $table->addColumn('target_id', Types::GUID);
        $table->addColumn('run_id', Types::GUID);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['resource', 'external_id'], 'uniq_idoklad_link');
    }

    private function idokladPull(Schema $schema): void
    {
        $table = $schema->createTable('idoklad_pull');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('started_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('finished_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('changed_since', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('counts', Types::JSON);
        $table->addColumn('requests_used', Types::INTEGER);
        $table->addColumn('latest_change', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('error', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['started_at'], 'idx_idoklad_pull_started');
    }

    private function idokladRecord(Schema $schema): void
    {
        $table = $schema->createTable('idoklad_record');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('resource', Types::STRING, ['length' => 32]);
        $table->addColumn('external_id', Types::INTEGER);
        $table->addColumn('payload', Types::TEXT);
        $table->addColumn('pulled_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('changed_at', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('pdf_key', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('pdf_sha256', Types::STRING, ['length' => 64, 'fixed' => true, 'notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['resource', 'external_id'], 'uniq_idoklad_record');
    }
}
