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
 * The Cash flow module (plan 30c): planned items, bank balance checkpoints,
 * the expected days of documents and the minimum balance of a firm. Built
 * with the schema API so it runs unchanged on SQLite and PostgreSQL.
 */
final class Version20261117000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the planned items, balance checkpoints, expected dates and minimum balances of Cash flow';
    }

    public function up(Schema $schema): void
    {
        $item = $schema->createTable('cash_flow_planned_item');
        $item->addColumn('id', Types::GUID);
        $item->addColumn('tenant_id', 'guid');
        $item->addColumn('legal_entity_id', Types::GUID);
        $item->addColumn('direction', Types::STRING, ['length' => 3]);
        $item->addColumn('name', Types::STRING, ['length' => 120]);
        $item->addColumn('category', Types::STRING, ['length' => 16]);
        $item->addColumn('amount_minor', Types::BIGINT);
        $item->addColumn('currency', Types::STRING, ['length' => 3]);
        $item->addColumn('counterparty_id', Types::GUID, ['notnull' => false]);
        $item->addColumn('first_on', Types::DATE_IMMUTABLE);
        $item->addColumn('recurrence_unit', Types::STRING, ['length' => 8, 'notnull' => false]);
        $item->addColumn('recurrence_every', Types::SMALLINT, ['notnull' => false]);
        $item->addColumn('last_day_of_month', Types::BOOLEAN);
        $item->addColumn('recurrence_end', Types::STRING, ['length' => 16, 'notnull' => false]);
        $item->addColumn('end_on', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $item->addColumn('end_after', Types::SMALLINT, ['notnull' => false]);
        $item->addColumn('included', Types::BOOLEAN);
        $item->addColumn('skipped_on', Types::JSON);
        $item->addColumn('match_account', Types::STRING, ['length' => 50, 'notnull' => false]);
        $item->addColumn('match_variable_symbol', Types::STRING, ['length' => 10, 'notnull' => false]);
        $item->addColumn('match_tolerance_minor', Types::BIGINT, ['notnull' => false]);
        $item->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $item->setPrimaryKey(['id']);
        $item->addIndex(['tenant_id'], 'idx_cash_flow_planned_item_tenant');
        $item->addIndex(['legal_entity_id'], 'idx_cash_flow_planned_item_firm');
        $item->addIndex(['counterparty_id'], 'idx_cash_flow_planned_item_counterparty');
        $item->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_cash_flow_planned_item_firm');
        $item->addForeignKeyConstraint('party', ['counterparty_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_cash_flow_planned_item_counterparty');

        $checkpoint = $schema->createTable('cash_flow_checkpoint');
        $checkpoint->addColumn('id', Types::GUID);
        $checkpoint->addColumn('tenant_id', 'guid');
        $checkpoint->addColumn('firm_bank_account_id', Types::GUID);
        $checkpoint->addColumn('balance_on', Types::DATE_IMMUTABLE);
        $checkpoint->addColumn('amount_minor', Types::BIGINT);
        $checkpoint->addColumn('recorded_at', Types::DATETIME_IMMUTABLE);
        $checkpoint->setPrimaryKey(['id']);
        $checkpoint->addIndex(['tenant_id'], 'idx_cash_flow_checkpoint_tenant');
        $checkpoint->addUniqueIndex(['firm_bank_account_id', 'balance_on'], 'uniq_cash_flow_checkpoint_day');
        $checkpoint->addForeignKeyConstraint('firm_bank_account', ['firm_bank_account_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_cash_flow_checkpoint_account');

        $expected = $schema->createTable('cash_flow_expected_date');
        $expected->addColumn('id', Types::GUID);
        $expected->addColumn('tenant_id', 'guid');
        $expected->addColumn('subject_type', Types::STRING, ['length' => 40]);
        $expected->addColumn('subject_id', Types::STRING, ['length' => 64]);
        $expected->addColumn('expected_on', Types::DATE_IMMUTABLE);
        $expected->setPrimaryKey(['id']);
        $expected->addIndex(['tenant_id'], 'idx_cash_flow_expected_date_tenant');
        $expected->addUniqueIndex(['tenant_id', 'subject_type', 'subject_id'], 'uniq_cash_flow_expected_date_subject');

        $minimum = $schema->createTable('cash_flow_minimum_balance');
        $minimum->addColumn('id', Types::GUID);
        $minimum->addColumn('tenant_id', 'guid');
        $minimum->addColumn('legal_entity_id', Types::GUID);
        $minimum->addColumn('amount_minor', Types::BIGINT);
        $minimum->setPrimaryKey(['id']);
        $minimum->addIndex(['tenant_id'], 'idx_cash_flow_minimum_balance_tenant');
        $minimum->addUniqueIndex(['legal_entity_id'], 'uniq_cash_flow_minimum_balance_firm');
        $minimum->addForeignKeyConstraint('legal_entity', ['legal_entity_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_cash_flow_minimum_balance_firm');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('cash_flow_minimum_balance');
        $schema->dropTable('cash_flow_expected_date');
        $schema->dropTable('cash_flow_checkpoint');
        $schema->dropTable('cash_flow_planned_item');
    }
}
