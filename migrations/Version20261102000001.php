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
 * The number series of a firm and their counters. New tables, so nothing to
 * backfill. The counter statements need SQLite 3.35 or newer.
 */
final class Version20261102000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add number series and their counters';
    }

    public function up(Schema $schema): void
    {
        $series = $schema->createTable('series');
        $series->addColumn('id', Types::GUID);
        $series->addColumn('tenant_id', 'tenant_id');
        $series->addColumn('legal_entity_id', Types::GUID);
        $series->addColumn('name', Types::STRING, ['length' => 100]);
        $series->addColumn('format', Types::STRING, ['length' => 64]);
        $series->addColumn('kind', Types::STRING, ['length' => 32]);
        $series->addColumn('default_flag', Types::BOOLEAN, ['notnull' => false]);
        $series->addColumn('starts', Types::JSON);
        $series->addColumn('archived', Types::BOOLEAN);
        $series->setPrimaryKey(['id']);
        $series->addIndex(['tenant_id'], 'idx_series_tenant');
        $series->addIndex(['legal_entity_id'], 'idx_series_firm');
        $series->addUniqueIndex(['tenant_id', 'legal_entity_id', 'name'], 'uniq_series_name');
        $series->addUniqueIndex(['legal_entity_id', 'kind', 'default_flag'], 'uniq_series_default');
        $series->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_series_firm');

        $counter = $schema->createTable('series_counter');
        $counter->addColumn('id', Types::GUID);
        $counter->addColumn('tenant_id', 'tenant_id');
        $counter->addColumn('series_id', Types::GUID);
        $counter->addColumn('period', Types::STRING, ['length' => 8]);
        $counter->addColumn('last_value', Types::INTEGER);
        $counter->setPrimaryKey(['id']);
        $counter->addIndex(['tenant_id'], 'idx_series_counter_tenant');
        $counter->addUniqueIndex(['series_id', 'period'], 'uniq_series_counter');
        $counter->addForeignKeyConstraint('series', ['series_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_series_counter_series');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('series_counter');
        $schema->dropTable('series');
    }
}
