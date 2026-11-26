<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * The tables of Sales (plan 32f), with its stages (P1 of plan 32: inquiry,
 * meeting, proposal, negotiation) and lost reasons (P2: price, timing, another
 * supplier, no response, other). They have no name of their own, so each
 * shows under its key's name in the person's language until someone renames it.
 */
final class Version20261123140000 extends AbstractMigration
{
    private const array STAGES = ['inquiry', 'meeting', 'proposal', 'negotiation'];
    private const array LOST_REASONS = ['price', 'timing', 'other_supplier', 'no_response', 'other'];

    public function getDescription(): string
    {
        return 'Create the tables of Sales with its stages and lost reasons';
    }

    public function up(Schema $schema): void
    {
        foreach (['sales_stage' => 'stage_key', 'sales_lost_reason' => 'reason_key'] as $name => $key) {
            $table = $schema->createTable($name);
            $table->addColumn('id', Types::GUID);
            $table->addColumn($key, Types::STRING, ['length' => 40, 'notnull' => false]);
            $table->addColumn('name', Types::STRING, ['length' => 80, 'notnull' => false]);
            $table->addColumn('position', Types::INTEGER);
            $table->addColumn('archived_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex([$key], 'uniq_'.$name.'_key');
        }

        $table = $schema->createTable('sales_deal');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('title', Types::STRING, ['length' => 200]);
        $table->addColumn('description', Types::TEXT);
        $table->addColumn('client_party_id', Types::GUID);
        $table->addColumn('owner_user_id', Types::GUID);
        $table->addColumn('stage_id', Types::GUID);
        $table->addColumn('status', Types::STRING, ['length' => 16]);
        $table->addColumn('won_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('lost_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('lost_reason_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('lost_note', Types::STRING, ['length' => 1000, 'notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('trashed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('trashed_by', Types::GUID, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['client_party_id'], 'idx_sales_deal_client');
        $table->addIndex(['stage_id'], 'idx_sales_deal_stage');

        $table = $schema->createTable('sales_deal_contact');
        $table->addColumn('deal_id', Types::GUID);
        $table->addColumn('party_person_id', Types::GUID);
        $table->addColumn('position', Types::INTEGER);
        $table->setPrimaryKey(['deal_id', 'party_person_id']);
        $table->addIndex(['party_person_id'], 'idx_sales_deal_contact_person');

        $table = $schema->createTable('sales_deal_line');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('deal_id', Types::GUID);
        $table->addColumn('position', Types::INTEGER);
        $table->addColumn('product_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('name', Types::STRING, ['length' => 200]);
        $table->addColumn('unit', Types::STRING, ['length' => 32]);
        $table->addColumn('quantity', Types::DECIMAL, ['precision' => 15, 'scale' => 3]);
        $table->addColumn('unit_price_minor', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('currency', Types::STRING, ['length' => 3, 'notnull' => false]);
        $table->addColumn('billing_kind', Types::STRING, ['length' => 16]);
        $table->addColumn('recurrence_unit', Types::STRING, ['length' => 8, 'notnull' => false]);
        $table->addColumn('recurrence_interval', Types::SMALLINT, ['notnull' => false]);
        $table->addColumn('cycles', Types::SMALLINT, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['deal_id'], 'idx_sales_deal_line_deal');

        $table = $schema->createTable('sales_deal_link');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('from_deal_id', Types::GUID);
        $table->addColumn('to_deal_id', Types::GUID);
        $table->addColumn('link_type', Types::STRING, ['length' => 32]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['from_deal_id', 'to_deal_id', 'link_type'], 'uniq_sales_deal_link');
        $table->addIndex(['to_deal_id'], 'idx_sales_deal_link_to');
    }

    public function postUp(Schema $schema): void
    {
        // A dry run creates no table to fill.
        if (!$this->connection->createSchemaManager()->tablesExist(['sales_stage', 'sales_lost_reason'])) {
            return;
        }
        foreach (['sales_stage' => ['stage_key', self::STAGES], 'sales_lost_reason' => ['reason_key', self::LOST_REASONS]] as $table => [$column, $keys]) {
            foreach ($keys as $position => $key) {
                $this->connection->insert($table, [
                    'id' => Uuid::v7()->toRfc4122(),
                    $column => $key,
                    'name' => null,
                    'position' => $position,
                    'archived_at' => null,
                    'created_at' => new \DateTimeImmutable(),
                ], ['created_at' => Types::DATETIME_IMMUTABLE]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['sales_deal_link', 'sales_deal_line', 'sales_deal_contact', 'sales_deal', 'sales_lost_reason', 'sales_stage'] as $table) {
            $schema->dropTable($table);
        }
    }
}
