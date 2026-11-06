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
 * The background jobs of the move from iDoklad, and when an import run was taken
 * back. No run was taken back so far, so the column starts empty.
 */
final class Version20261106090001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the jobs of the move from iDoklad and the undo of an import run';
    }

    public function up(Schema $schema): void
    {
        $job = $schema->createTable('idoklad_job');
        $job->addColumn('id', Types::GUID);
        $job->addColumn('tenant_id', 'tenant_id');
        $job->addColumn('legal_entity_id', Types::GUID);
        $job->addColumn('kind', Types::STRING, ['length' => 8]);
        $job->addColumn('state', Types::STRING, ['length' => 8]);
        $job->addColumn('requested_at', Types::DATETIME_IMMUTABLE);
        $job->addColumn('started_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $job->addColumn('finished_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $job->addColumn('remind_from', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $job->addColumn('result_id', Types::GUID, ['notnull' => false]);
        $job->addColumn('error', Types::STRING, ['length' => 255, 'notnull' => false]);
        $job->setPrimaryKey(['id']);
        $job->addIndex(['tenant_id'], 'idx_idoklad_job_tenant');
        $job->addIndex(['legal_entity_id', 'requested_at'], 'idx_idoklad_job_firm');
        $job->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_idoklad_job_firm');

        $schema->getTable('idoklad_import_run')->addColumn('undone_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('idoklad_import_run')->dropColumn('undone_at');
        $schema->dropTable('idoklad_job');
    }
}
