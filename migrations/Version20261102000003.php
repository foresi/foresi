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
 * The runs of scheduled jobs: one row per job and tenant. A new table, so nothing
 * to backfill.
 */
final class Version20261102000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the runs of scheduled jobs';
    }

    public function up(Schema $schema): void
    {
        $run = $schema->createTable('scheduled_job_run');
        $run->addColumn('id', Types::GUID);
        $run->addColumn('tenant_id', 'tenant_id');
        $run->addColumn('job', Types::STRING, ['length' => 64]);
        $run->addColumn('last_period', Types::STRING, ['length' => 32, 'notnull' => false]);
        $run->addColumn('started_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $run->addColumn('finished_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $run->addColumn('last_error', Types::TEXT, ['notnull' => false]);
        $run->setPrimaryKey(['id']);
        $run->addIndex(['tenant_id'], 'idx_scheduled_job_run_tenant');
        $run->addUniqueIndex(['tenant_id', 'job'], 'uniq_scheduled_job_run');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('scheduled_job_run');
    }
}
