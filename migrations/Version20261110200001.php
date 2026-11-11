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
 * The evidence series of received documents, one per firm, and its counter, one
 * row per period, written only by the atomic upsert of DoctrineEvidenceNumbers.
 * New tables, so nothing existing needs a value; a firm gets its series when it
 * first records a received document.
 */
final class Version20261110200001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the evidence series of received documents and its counter';
    }

    public function up(Schema $schema): void
    {
        $series = $schema->createTable('evidence_series');
        $series->addColumn('id', Types::GUID);
        $series->addColumn('tenant_id', 'tenant_id');
        $series->addColumn('legal_entity_id', Types::GUID);
        $series->addColumn('format', Types::STRING, ['length' => 32]);
        $series->setPrimaryKey(['id']);
        $series->addIndex(['tenant_id'], 'idx_evidence_series_tenant');
        $series->addUniqueIndex(['legal_entity_id'], 'uniq_evidence_series_firm');
        $series->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_evidence_series_firm');

        $counter = $schema->createTable('evidence_counter');
        $counter->addColumn('id', Types::GUID);
        $counter->addColumn('tenant_id', 'tenant_id');
        $counter->addColumn('evidence_series_id', Types::GUID);
        $counter->addColumn('period', Types::STRING, ['length' => 8]);
        $counter->addColumn('last_value', Types::INTEGER);
        $counter->setPrimaryKey(['id']);
        $counter->addIndex(['tenant_id'], 'idx_evidence_counter_tenant');
        $counter->addUniqueIndex(['evidence_series_id', 'period'], 'uniq_evidence_counter');
        $counter->addForeignKeyConstraint('evidence_series', ['evidence_series_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_evidence_counter_series');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('evidence_counter');
        $schema->dropTable('evidence_series');
    }
}
