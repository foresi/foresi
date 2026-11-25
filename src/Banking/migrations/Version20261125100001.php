<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The statements that come in, and the channel and source of every movement, for
 * the intake that all channels share. Every movement so far came by bank mail: its
 * source is the parser that read its message, or "mail" when none is known. The
 * second step makes channel and source required.
 */
final class Version20261125100001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add bank statements, and the channel, source and corroboration of bank movements';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('bank_statement');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('firm_bank_account_id', Types::GUID);
        $table->addColumn('channel', Types::STRING, ['length' => 16]);
        $table->addColumn('source_id', Types::STRING, ['length' => 64]);
        $table->addColumn('statement_ref', Types::STRING, ['length' => 64, 'notnull' => false]);
        $table->addColumn('sequence_kind', Types::STRING, ['length' => 16, 'notnull' => false]);
        $table->addColumn('sequence_number', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('sequence_year', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('period_from', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('period_to', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('currency', Types::STRING, ['length' => 3, 'fixed' => true]);
        $table->addColumn('opening_minor', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('closing_minor', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('entries_net_minor', Types::BIGINT);
        $table->addColumn('entry_count', Types::INTEGER);
        $table->addColumn('trust', Types::STRING, ['length' => 16]);
        $table->addColumn('trust_basis', Types::STRING, ['length' => 32]);
        $table->addColumn('actor_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('file_sha256', Types::STRING, ['length' => 64, 'fixed' => true, 'notnull' => false]);
        $table->addColumn('file_name', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('received_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['firm_bank_account_id', 'period_to'], 'idx_bank_statement_account');
        $table->addForeignKeyConstraint('firm_bank_account', ['firm_bank_account_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_bank_statement_account');

        $movement = $schema->getTable('bank_movement');
        $movement->addColumn('channel', Types::STRING, ['length' => 16, 'notnull' => false]);
        $movement->addColumn('source_id', Types::STRING, ['length' => 64, 'notnull' => false]);
        $movement->addColumn('bank_statement_id', Types::GUID, ['notnull' => false]);
        $movement->addColumn('corroborated_source_id', Types::STRING, ['length' => 64, 'notnull' => false]);
        $movement->addColumn('corroborated_reference', Types::STRING, ['length' => 64, 'notnull' => false]);
        $movement->addColumn('corroborated_statement_id', Types::GUID, ['notnull' => false]);
        $movement->addColumn('corroborated_message_id', Types::GUID, ['notnull' => false]);
        $movement->addColumn('corroborated_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $movement->addIndex(['firm_bank_account_id', 'source_id', 'bank_reference'], 'idx_bank_movement_source_reference');
        $movement->addIndex(['bank_statement_id'], 'idx_bank_movement_statement');
    }

    public function postUp(Schema $schema): void
    {
        $this->connection->executeStatement(<<<'SQL'
            UPDATE bank_movement SET channel = 'mail', source_id = COALESCE(
                (SELECT i.parser_id FROM inbound_message i WHERE i.id = bank_movement.inbound_message_id),
                'mail'
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $movement = $schema->getTable('bank_movement');
        $movement->dropIndex('idx_bank_movement_source_reference');
        $movement->dropIndex('idx_bank_movement_statement');
        foreach (['channel', 'source_id', 'bank_statement_id', 'corroborated_source_id', 'corroborated_reference', 'corroborated_statement_id', 'corroborated_message_id', 'corroborated_at'] as $column) {
            $movement->dropColumn($column);
        }
        $schema->dropTable('bank_statement');
    }
}
