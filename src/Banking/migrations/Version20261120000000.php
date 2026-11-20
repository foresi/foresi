<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The baseline of Banking: its 4 tables as the global migrations left
 * them (ADR 0034). A database migrated before the module packages moves onto
 * it with app:migrations:rebase, which runs none of this.
 */
final class Version20261120000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the tables of Banking';
    }

    public function up(Schema $schema): void
    {
        $this->bankFeed($schema);
        $this->bankMovement($schema);
        $this->firmBankPairing($schema);
        $this->inboundMessage($schema);
    }

    /**
     * A table of one row keeps it so: the unique one_row column, and on
     * PostgreSQL a check that it is true. SQLite cannot add a check to a
     * table, so there the unique index alone stops a second row.
     */
    public function postUp(Schema $schema): void
    {
        // A dry run creates no table to check.
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform
            || !$this->connection->createSchemaManager()->tablesExist(['firm_bank_pairing'])) {
            return;
        }
        $this->connection->executeStatement('ALTER TABLE firm_bank_pairing ADD CONSTRAINT chk_firm_bank_pairing_one_row CHECK (one_row)');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('inbound_message');
        $schema->dropTable('firm_bank_pairing');
        $schema->dropTable('bank_movement');
        $schema->dropTable('bank_feed');
    }

    private function bankFeed(Schema $schema): void
    {
        $table = $schema->createTable('bank_feed');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('firm_bank_account_id', Types::GUID);
        $table->addColumn('token_hash', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('token_sealed', Types::TEXT);
        $table->addColumn('generation', Types::INTEGER);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('rotated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('last_received_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['firm_bank_account_id'], 'uniq_bank_feed_account');
        $table->addUniqueIndex(['token_hash'], 'uniq_bank_feed_token');
        $table->addForeignKeyConstraint('firm_bank_account', ['firm_bank_account_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_bank_feed_account');
    }

    private function bankMovement(Schema $schema): void
    {
        $table = $schema->createTable('bank_movement');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('firm_bank_account_id', Types::GUID);
        $table->addColumn('inbound_message_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('direction', Types::STRING, ['length' => 16]);
        $table->addColumn('amount_minor', Types::BIGINT);
        $table->addColumn('currency', Types::STRING, ['length' => 3, 'fixed' => true]);
        $table->addColumn('booked_on', Types::DATE_IMMUTABLE);
        $table->addColumn('counter_account', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('counter_iban', Types::STRING, ['length' => 34, 'notnull' => false]);
        $table->addColumn('counter_name', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('variable_symbol', Types::STRING, ['length' => 10, 'notnull' => false]);
        $table->addColumn('constant_symbol', Types::STRING, ['length' => 10, 'notnull' => false]);
        $table->addColumn('specific_symbol', Types::STRING, ['length' => 10, 'notnull' => false]);
        $table->addColumn('message', Types::STRING, ['length' => 500, 'notnull' => false]);
        $table->addColumn('bank_reference', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('fingerprint', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('trust', Types::STRING, ['length' => 16]);
        $table->addColumn('trust_basis', Types::STRING, ['length' => 32]);
        $table->addColumn('state', Types::STRING, ['length' => 32]);
        $table->addColumn('unmatched_reason', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('payment_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('auto_pair_blocked', Types::BOOLEAN);
        $table->addColumn('version', Types::INTEGER);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['firm_bank_account_id', 'booked_on'], 'idx_bank_movement_account_booked');
        $table->addIndex(['fingerprint'], 'idx_bank_movement_fingerprint');
        $table->addIndex(['inbound_message_id'], 'idx_bank_movement_message');
        $table->addIndex(['payment_id'], 'idx_bank_movement_payment');
        $table->addIndex(['firm_bank_account_id'], 'idx_c59d8f703f71cfc6');
        $table->addForeignKeyConstraint('firm_bank_account', ['firm_bank_account_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_bank_movement_account');
    }

    private function firmBankPairing(Schema $schema): void
    {
        $table = $schema->createTable('firm_bank_pairing');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('auto_pairing', Types::BOOLEAN, ['default' => true]);
        $table->addColumn('small_difference', Types::BOOLEAN, ['default' => false]);
        $table->addColumn('one_row', Types::BOOLEAN, ['default' => true]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['one_row'], 'uniq_firm_bank_pairing_one_row');
    }

    private function inboundMessage(Schema $schema): void
    {
        $table = $schema->createTable('inbound_message');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('bank_feed_id', Types::GUID);
        $table->addColumn('channel', Types::STRING, ['length' => 16]);
        $table->addColumn('dedup_key', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('raw_key', Types::STRING, ['length' => 128]);
        $table->addColumn('raw_sha256', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('size', Types::INTEGER);
        $table->addColumn('from_address', Types::STRING, ['length' => 320, 'notnull' => false]);
        $table->addColumn('subject', Types::STRING, ['length' => 255]);
        $table->addColumn('received_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('status', Types::STRING, ['length' => 16]);
        $table->addColumn('trust', Types::STRING, ['length' => 16, 'notnull' => false]);
        $table->addColumn('trust_basis', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('parser_id', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('parser_version', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('failure', Types::STRING, ['length' => 32, 'notnull' => false]);
        $table->addColumn('failure_detail', Types::TEXT, ['notnull' => false]);
        $table->addColumn('attempts', Types::INTEGER);
        $table->addColumn('processed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['bank_feed_id'], 'idx_inbound_message_feed');
        $table->addIndex(['status'], 'idx_inbound_message_status');
        $table->addUniqueIndex(['dedup_key'], 'uniq_inbound_message_dedup');
    }
}
