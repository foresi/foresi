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
 * Bank movements on the firms' accounts. A new table, so nothing to backfill. The
 * message a movement came in is named by its id only, as its table comes later.
 */
final class Version20261103080001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add bank movements and where each stands in pairing';
    }

    public function up(Schema $schema): void
    {
        $movement = $schema->createTable('bank_movement');
        $movement->addColumn('id', Types::GUID);
        $movement->addColumn('tenant_id', 'tenant_id');
        $movement->addColumn('legal_entity_id', Types::GUID);
        $movement->addColumn('firm_bank_account_id', Types::GUID);
        $movement->addColumn('inbound_message_id', Types::GUID, ['notnull' => false]);
        $movement->addColumn('direction', Types::STRING, ['length' => 16]);
        $movement->addColumn('amount_minor', Types::BIGINT);
        $movement->addColumn('currency', Types::STRING, ['length' => 3, 'fixed' => true]);
        $movement->addColumn('booked_on', Types::DATE_IMMUTABLE);
        $movement->addColumn('counter_account', Types::STRING, ['length' => 64, 'notnull' => false]);
        $movement->addColumn('counter_iban', Types::STRING, ['length' => 34, 'notnull' => false]);
        $movement->addColumn('counter_name', Types::STRING, ['length' => 255, 'notnull' => false]);
        $movement->addColumn('variable_symbol', Types::STRING, ['length' => 10, 'notnull' => false]);
        $movement->addColumn('constant_symbol', Types::STRING, ['length' => 10, 'notnull' => false]);
        $movement->addColumn('specific_symbol', Types::STRING, ['length' => 10, 'notnull' => false]);
        $movement->addColumn('message', Types::STRING, ['length' => 500, 'notnull' => false]);
        $movement->addColumn('bank_reference', Types::STRING, ['length' => 64, 'notnull' => false]);
        $movement->addColumn('fingerprint', Types::STRING, ['length' => 64, 'fixed' => true]);
        $movement->addColumn('trust', Types::STRING, ['length' => 16]);
        $movement->addColumn('trust_basis', Types::STRING, ['length' => 32]);
        $movement->addColumn('state', Types::STRING, ['length' => 32]);
        $movement->addColumn('unmatched_reason', Types::STRING, ['length' => 32, 'notnull' => false]);
        $movement->addColumn('payment_id', Types::GUID, ['notnull' => false]);
        $movement->addColumn('auto_pair_blocked', Types::BOOLEAN);
        $movement->addColumn('version', Types::INTEGER);
        $movement->setPrimaryKey(['id']);
        $movement->addIndex(['tenant_id'], 'idx_bank_movement_tenant');
        $movement->addIndex(['fingerprint'], 'idx_bank_movement_fingerprint');
        $movement->addIndex(['legal_entity_id'], 'idx_bank_movement_firm');
        $movement->addIndex(['firm_bank_account_id', 'booked_on'], 'idx_bank_movement_account_booked');
        $movement->addIndex(['inbound_message_id'], 'idx_bank_movement_message');
        $movement->addIndex(['payment_id'], 'idx_bank_movement_payment');
        $movement->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_bank_movement_firm');
        $movement->addForeignKeyConstraint('firm_bank_account', ['firm_bank_account_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_bank_movement_account');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('bank_movement');
    }
}
