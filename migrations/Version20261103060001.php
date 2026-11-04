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
 * The inbound address of a bank account, kept as the hash and a sealed copy of
 * its token, and a firm's own pairing switches.
 */
final class Version20261103060001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the inbound addresses of bank accounts and the pairing switches of firms';
    }

    public function up(Schema $schema): void
    {
        $feed = $schema->createTable('bank_feed');
        $feed->addColumn('id', Types::GUID);
        $feed->addColumn('tenant_id', 'tenant_id');
        $feed->addColumn('firm_bank_account_id', Types::GUID);
        $feed->addColumn('token_hash', Types::STRING, ['length' => 64, 'fixed' => true]);
        $feed->addColumn('token_sealed', Types::TEXT);
        $feed->addColumn('generation', Types::INTEGER);
        $feed->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $feed->addColumn('rotated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $feed->addColumn('last_received_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $feed->setPrimaryKey(['id']);
        $feed->addIndex(['tenant_id'], 'idx_bank_feed_tenant');
        $feed->addUniqueIndex(['firm_bank_account_id'], 'uniq_bank_feed_account');
        $feed->addUniqueIndex(['token_hash'], 'uniq_bank_feed_token');
        $feed->addForeignKeyConstraint('firm_bank_account', ['firm_bank_account_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_bank_feed_account');

        $pairing = $schema->createTable('firm_bank_pairing');
        $pairing->addColumn('id', Types::GUID);
        $pairing->addColumn('tenant_id', 'tenant_id');
        $pairing->addColumn('legal_entity_id', Types::GUID);
        $pairing->addColumn('auto_pairing', Types::BOOLEAN, ['default' => true]);
        $pairing->addColumn('small_difference', Types::BOOLEAN, ['default' => false]);
        $pairing->setPrimaryKey(['id']);
        $pairing->addIndex(['tenant_id'], 'idx_firm_bank_pairing_tenant');
        $pairing->addUniqueIndex(['legal_entity_id'], 'uniq_firm_bank_pairing_firm');
        $pairing->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_firm_bank_pairing_firm');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('firm_bank_pairing');
        $schema->dropTable('bank_feed');
    }
}
