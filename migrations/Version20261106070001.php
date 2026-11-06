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
 * What the iDoklad records of a firm became in Foresi, and the imports that
 * linked them. New tables, so nothing to backfill.
 */
final class Version20261106070001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the links of iDoklad records and the runs of the iDoklad import';
    }

    public function up(Schema $schema): void
    {
        $link = $schema->createTable('idoklad_link');
        $link->addColumn('id', Types::GUID);
        $link->addColumn('tenant_id', 'tenant_id');
        $link->addColumn('legal_entity_id', Types::GUID);
        $link->addColumn('resource', Types::STRING, ['length' => 32]);
        $link->addColumn('external_id', Types::INTEGER);
        $link->addColumn('target_id', Types::GUID);
        $link->addColumn('run_id', Types::GUID);
        $link->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $link->setPrimaryKey(['id']);
        $link->addIndex(['tenant_id'], 'idx_idoklad_link_tenant');
        $link->addUniqueIndex(['legal_entity_id', 'resource', 'external_id'], 'uniq_idoklad_link');
        $link->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_idoklad_link_firm');

        $run = $schema->createTable('idoklad_import_run');
        $run->addColumn('id', Types::GUID);
        $run->addColumn('tenant_id', 'tenant_id');
        $run->addColumn('legal_entity_id', Types::GUID);
        $run->addColumn('mode', Types::STRING, ['length' => 8]);
        $run->addColumn('pull_id', Types::GUID, ['notnull' => false]);
        $run->addColumn('started_at', Types::DATETIME_IMMUTABLE);
        $run->addColumn('finished_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $run->addColumn('counts', Types::JSON);
        $run->addColumn('report', Types::JSON);
        $run->addColumn('error', Types::STRING, ['length' => 255, 'notnull' => false]);
        $run->setPrimaryKey(['id']);
        $run->addIndex(['tenant_id'], 'idx_idoklad_import_run_tenant');
        $run->addIndex(['legal_entity_id', 'started_at'], 'idx_idoklad_import_run_firm');
        $run->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_idoklad_import_run_firm');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('idoklad_import_run');
        $schema->dropTable('idoklad_link');
    }
}
