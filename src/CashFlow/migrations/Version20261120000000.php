<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The baseline of Cash flow: its 4 tables as the global migrations left
 * them (ADR 0034). A database migrated before the module packages moves onto
 * it with app:migrations:rebase, which runs none of this.
 */
final class Version20261120000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the tables of Cash flow';
    }

    public function up(Schema $schema): void
    {
        $this->cashFlowCheckpoint($schema);
        $this->cashFlowExpectedDate($schema);
        $this->cashFlowMinimumBalance($schema);
        $this->cashFlowPlannedItem($schema);
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
            || !$this->connection->createSchemaManager()->tablesExist(['cash_flow_minimum_balance'])) {
            return;
        }
        $this->connection->executeStatement('ALTER TABLE cash_flow_minimum_balance ADD CONSTRAINT chk_cash_flow_minimum_balance_one_row CHECK (one_row)');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('cash_flow_planned_item');
        $schema->dropTable('cash_flow_minimum_balance');
        $schema->dropTable('cash_flow_expected_date');
        $schema->dropTable('cash_flow_checkpoint');
    }

    private function cashFlowCheckpoint(Schema $schema): void
    {
        $table = $schema->createTable('cash_flow_checkpoint');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('firm_bank_account_id', Types::GUID);
        $table->addColumn('balance_on', Types::DATE_IMMUTABLE);
        $table->addColumn('amount_minor', Types::BIGINT);
        $table->addColumn('recorded_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['firm_bank_account_id'], 'idx_7bcf9cf53f71cfc6');
        $table->addUniqueIndex(['firm_bank_account_id', 'balance_on'], 'uniq_cash_flow_checkpoint_day');
        $table->addForeignKeyConstraint('firm_bank_account', ['firm_bank_account_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_cash_flow_checkpoint_account');
    }

    private function cashFlowExpectedDate(Schema $schema): void
    {
        $table = $schema->createTable('cash_flow_expected_date');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('subject_type', Types::STRING, ['length' => 40]);
        $table->addColumn('subject_id', Types::STRING, ['length' => 64]);
        $table->addColumn('expected_on', Types::DATE_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['subject_type', 'subject_id'], 'uniq_cash_flow_expected_date_subject');
    }

    private function cashFlowMinimumBalance(Schema $schema): void
    {
        $table = $schema->createTable('cash_flow_minimum_balance');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('amount_minor', Types::BIGINT);
        $table->addColumn('one_row', Types::BOOLEAN, ['default' => true]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['one_row'], 'uniq_cash_flow_minimum_balance_one_row');
    }

    private function cashFlowPlannedItem(Schema $schema): void
    {
        $table = $schema->createTable('cash_flow_planned_item');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('direction', Types::STRING, ['length' => 3]);
        $table->addColumn('name', Types::STRING, ['length' => 120]);
        $table->addColumn('category', Types::STRING, ['length' => 16]);
        $table->addColumn('amount_minor', Types::BIGINT);
        $table->addColumn('currency', Types::STRING, ['length' => 3]);
        $table->addColumn('counterparty_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('first_on', Types::DATE_IMMUTABLE);
        $table->addColumn('recurrence_unit', Types::STRING, ['length' => 8, 'notnull' => false]);
        $table->addColumn('recurrence_every', Types::SMALLINT, ['notnull' => false]);
        $table->addColumn('last_day_of_month', Types::BOOLEAN);
        $table->addColumn('recurrence_end', Types::STRING, ['length' => 16, 'notnull' => false]);
        $table->addColumn('end_on', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('end_after', Types::SMALLINT, ['notnull' => false]);
        $table->addColumn('included', Types::BOOLEAN);
        $table->addColumn('skipped_on', Types::JSON);
        $table->addColumn('match_account', Types::STRING, ['length' => 50, 'notnull' => false]);
        $table->addColumn('match_variable_symbol', Types::STRING, ['length' => 10, 'notnull' => false]);
        $table->addColumn('match_tolerance_minor', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['counterparty_id'], 'idx_cash_flow_planned_item_counterparty');
        $table->addForeignKeyConstraint('party', ['counterparty_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_cash_flow_planned_item_counterparty');
    }
}
