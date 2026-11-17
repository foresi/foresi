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
 * One instance serves one customer (ADR 0035), the last step: the scheduled
 * job runs lose their tenant column, so each job has one row, and the tenant
 * table goes. Its name and time zone are not copied anywhere: the zone is
 * APP_TIMEZONE, else the country of the first active firm, and the name shown
 * is that of the first active firm. The guard (Version20261117210001) made sure
 * the database holds at most one tenant, so no job has two rows.
 *
 * Going down brings back an empty tenant table and the tenant column, not
 * null, so it works only while the scheduled job runs are empty. With data,
 * restore the dump taken before this migration.
 */
final class Version20261118000007 extends AbstractMigration
{
    private const string RUNS = 'scheduled_job_run';

    public function getDescription(): string
    {
        return 'Drop the tenant column from the scheduled job runs and the tenant table';
    }

    public function up(Schema $schema): void
    {
        $runs = $schema->getTable(self::RUNS);
        $runs->dropIndex('uniq_scheduled_job_run');
        $runs->dropIndex('idx_scheduled_job_run_tenant');
        $runs->dropColumn('tenant_id');
        $runs->addUniqueIndex(['job'], 'uniq_scheduled_job_run');

        $schema->dropTable('tenant');
    }

    public function preDown(Schema $schema): void
    {
        $this->abortIf(
            false !== $this->connection->fetchOne('SELECT 1 FROM '.self::RUNS),
            'The scheduled job runs hold rows, which have no tenant to go back to: restore the dump taken before this migration instead.',
        );
    }

    public function down(Schema $schema): void
    {
        $tenant = $schema->createTable('tenant');
        $tenant->addColumn('id', Types::GUID);
        $tenant->addColumn('name', Types::STRING, ['length' => 255]);
        $tenant->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $tenant->addColumn('timezone', Types::STRING, ['length' => 64, 'notnull' => false]);
        $tenant->setPrimaryKey(['id']);

        $runs = $schema->getTable(self::RUNS);
        $runs->dropIndex('uniq_scheduled_job_run');
        $runs->addColumn('tenant_id', Types::GUID, ['notnull' => true]);
        $runs->addIndex(['tenant_id'], 'idx_scheduled_job_run_tenant');
        $runs->addUniqueIndex(['tenant_id', 'job'], 'uniq_scheduled_job_run');
    }
}
