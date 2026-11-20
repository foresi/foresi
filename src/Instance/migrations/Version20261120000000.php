<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Instance\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The baseline of Instance: its 2 tables as the global migrations left
 * them (ADR 0034). A database migrated before the module packages moves onto
 * it with app:migrations:rebase, which runs none of this.
 */
final class Version20261120000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the tables of Instance';
    }

    public function up(Schema $schema): void
    {
        $this->heartbeat($schema);
        $this->scheduledJobRun($schema);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('scheduled_job_run');
        $schema->dropTable('heartbeat');
    }

    private function heartbeat(Schema $schema): void
    {
        $table = $schema->createTable('heartbeat');
        $table->addColumn('name', Types::STRING, ['length' => 32]);
        $table->addColumn('beat_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['name']);
    }

    private function scheduledJobRun(Schema $schema): void
    {
        $table = $schema->createTable('scheduled_job_run');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('job', Types::STRING, ['length' => 64]);
        $table->addColumn('last_period', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('started_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('finished_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('last_error', Types::TEXT, ['notnull' => false]);
        $table->addColumn('queued_period', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('queued_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['job'], 'uniq_scheduled_job_run');
    }
}
