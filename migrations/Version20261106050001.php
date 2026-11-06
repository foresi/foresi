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
 * The iDoklad credentials of a firm and the snapshot of its account. New
 * tables, so nothing to backfill.
 */
final class Version20261106050001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the iDoklad connection of a firm and the snapshot of its account';
    }

    public function up(Schema $schema): void
    {
        $connection = $schema->createTable('idoklad_connection');
        $connection->addColumn('id', Types::GUID);
        $connection->addColumn('tenant_id', 'tenant_id');
        $connection->addColumn('legal_entity_id', Types::GUID);
        $connection->addColumn('client_id', Types::STRING, ['length' => 255]);
        $connection->addColumn('secret_sealed', Types::TEXT);
        $connection->setPrimaryKey(['id']);
        $connection->addIndex(['tenant_id'], 'idx_idoklad_connection_tenant');
        $connection->addUniqueIndex(['legal_entity_id'], 'uniq_idoklad_connection_firm');
        $connection->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_idoklad_connection_firm');

        $pull = $schema->createTable('idoklad_pull');
        $pull->addColumn('id', Types::GUID);
        $pull->addColumn('tenant_id', 'tenant_id');
        $pull->addColumn('legal_entity_id', Types::GUID);
        $pull->addColumn('started_at', Types::DATETIME_IMMUTABLE);
        $pull->addColumn('finished_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $pull->addColumn('changed_since', Types::STRING, ['length' => 32, 'notnull' => false]);
        $pull->addColumn('counts', Types::JSON);
        $pull->addColumn('requests_used', Types::INTEGER);
        $pull->addColumn('latest_change', Types::STRING, ['length' => 32, 'notnull' => false]);
        $pull->addColumn('error', Types::STRING, ['length' => 255, 'notnull' => false]);
        $pull->setPrimaryKey(['id']);
        $pull->addIndex(['tenant_id'], 'idx_idoklad_pull_tenant');
        $pull->addIndex(['legal_entity_id', 'started_at'], 'idx_idoklad_pull_firm');
        $pull->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_idoklad_pull_firm');

        $record = $schema->createTable('idoklad_record');
        $record->addColumn('id', Types::GUID);
        $record->addColumn('tenant_id', 'tenant_id');
        $record->addColumn('legal_entity_id', Types::GUID);
        $record->addColumn('resource', Types::STRING, ['length' => 32]);
        $record->addColumn('external_id', Types::INTEGER);
        $record->addColumn('payload', Types::TEXT);
        $record->addColumn('pulled_at', Types::DATETIME_IMMUTABLE);
        $record->addColumn('changed_at', Types::STRING, ['length' => 32, 'notnull' => false]);
        $record->addColumn('pdf_key', Types::STRING, ['length' => 255, 'notnull' => false]);
        $record->addColumn('pdf_sha256', Types::STRING, ['length' => 64, 'fixed' => true, 'notnull' => false]);
        $record->setPrimaryKey(['id']);
        $record->addIndex(['tenant_id'], 'idx_idoklad_record_tenant');
        $record->addUniqueIndex(['legal_entity_id', 'resource', 'external_id'], 'uniq_idoklad_record');
        $record->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_idoklad_record_firm');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('idoklad_record');
        $schema->dropTable('idoklad_pull');
        $schema->dropTable('idoklad_connection');
    }
}
