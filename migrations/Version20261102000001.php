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
 * The number series of a firm and their counters, its invoicing settings, and its
 * logo and stamp. New tables and empty columns, so nothing to backfill. The counter
 * statements need SQLite 3.35 or newer.
 */
final class Version20261102000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add number series, their counters, firm invoicing settings, and the logo and stamp of a firm';
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

        $invoicing = $schema->createTable('firm_invoicing');
        $invoicing->addColumn('id', Types::GUID);
        $invoicing->addColumn('tenant_id', 'tenant_id');
        $invoicing->addColumn('legal_entity_id', Types::GUID);
        $invoicing->addColumn('rounding', Types::STRING, ['length' => 16]);
        $invoicing->addColumn('constant_symbol', Types::STRING, ['length' => 10]);
        $invoicing->addColumn('text_before', Types::TEXT);
        $invoicing->addColumn('text_after', Types::TEXT);
        $invoicing->addColumn('contact_email', Types::STRING, ['length' => 255]);
        $invoicing->addColumn('contact_phone', Types::STRING, ['length' => 32]);
        $invoicing->addColumn('contact_web', Types::STRING, ['length' => 255]);
        $invoicing->setPrimaryKey(['id']);
        $invoicing->addIndex(['tenant_id'], 'idx_firm_invoicing_tenant');
        $invoicing->addUniqueIndex(['legal_entity_id'], 'uniq_firm_invoicing_firm');
        $invoicing->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_firm_invoicing_firm');

        $firm = $schema->getTable('legal_entity');
        $firm->addColumn('logo', Types::STRING, ['length' => 72, 'notnull' => false]);
        $firm->addColumn('stamp', Types::STRING, ['length' => 72, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $firm = $schema->getTable('legal_entity');
        $firm->dropColumn('logo');
        $firm->dropColumn('stamp');

        $schema->dropTable('firm_invoicing');
        $schema->dropTable('series_counter');
        $schema->dropTable('series');
    }
}
