<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Foresi\Shared\Infrastructure\Doctrine\RunsOnlyForReal;
use Foresi\Shared\Infrastructure\Migration\ModuleRename;
use Foresi\Shared\Infrastructure\Migration\RenamesTables;

/**
 * The stored names of the deals take the module's id, deals: the tables
 * (sales_deal becomes deal, sales_stage deal_stage, …) with their indexes, the
 * events (sales.deal.created becomes deals.deal.created) in the event log, the
 * webhook deliveries and the endpoints' subscriptions (sales.* becomes deals.*),
 * and the keys of the lists in the saved views (sales.deals becomes
 * deals.deals). The rows stay. Going down names them the old way again.
 */
final class Version20261127150000 extends AbstractMigration implements RenamesTables, RunsOnlyForReal
{
    private const array TABLES = [
        'sales_stage' => 'deal_stage',
        'sales_lost_reason' => 'deal_lost_reason',
        'sales_deal' => 'deal',
        'sales_deal_contact' => 'deal_contact',
        'sales_deal_line' => 'deal_line',
        'sales_deal_link' => 'deal_link',
        'sales_billing_plan_entry' => 'deal_billing_plan_entry',
    ];

    /** Each index by its old name: the table it is on (its new name), its new name, its columns, whether it is unique. */
    private const array INDEXES = [
        'uniq_sales_stage_key' => ['deal_stage', 'uniq_deal_stage_key', ['stage_key'], true],
        'uniq_sales_lost_reason_key' => ['deal_lost_reason', 'uniq_deal_lost_reason_key', ['reason_key'], true],
        'idx_sales_deal_client' => ['deal', 'idx_deal_client', ['client_party_id'], false],
        'idx_sales_deal_stage' => ['deal', 'idx_deal_stage', ['stage_id'], false],
        'idx_sales_deal_contact_person' => ['deal_contact', 'idx_deal_contact_person', ['party_person_id'], false],
        'idx_sales_deal_line_deal' => ['deal_line', 'idx_deal_line_deal', ['deal_id'], false],
        'uniq_sales_deal_line_project' => ['deal_line', 'uniq_deal_line_project', ['project_id'], true],
        'uniq_sales_deal_link' => ['deal_link', 'uniq_deal_link', ['from_deal_id', 'to_deal_id', 'link_type'], true],
        'idx_sales_deal_link_to' => ['deal_link', 'idx_deal_link_to', ['to_deal_id'], false],
        'idx_sales_billing_plan_entry_deal' => ['deal_billing_plan_entry', 'idx_deal_billing_plan_entry_deal', ['deal_id'], false],
        'idx_sales_billing_plan_entry_fulfilled' => ['deal_billing_plan_entry', 'idx_deal_billing_plan_entry_fulfilled', ['fulfilled_type', 'fulfilled_id'], false],
        'uniq_sales_billing_plan_entry_line' => ['deal_billing_plan_entry', 'uniq_deal_billing_plan_entry_line', ['line_id'], true],
    ];

    private const array LISTS = [
        'sales.deals' => 'deals.deals',
        'sales.deal_stages' => 'deals.deal_stages',
        'sales.lost_reasons' => 'deals.lost_reasons',
    ];

    public function getDescription(): string
    {
        return 'Name the tables, events and lists of deals after the module deals';
    }

    public function renamedTables(): array
    {
        return self::TABLES;
    }

    public function up(Schema $schema): void
    {
        // The rows stay: postUp() renames in place what a schema change would drop and create.
    }

    public function postUp(Schema $schema): void
    {
        $rename = new ModuleRename($this->connection);
        $rename->renameTables(self::TABLES, self::INDEXES);
        $rename->renameEvents('sales.', 'deals.');
        $rename->renameListKeys(self::LISTS);
    }

    public function down(Schema $schema): void
    {
        // The rows change in postDown().
    }

    public function postDown(Schema $schema): void
    {
        $rename = new ModuleRename($this->connection);
        $rename->renameListKeys(array_flip(self::LISTS));
        $rename->renameEvents('deals.', 'sales.');
        $rename->renameTablesBack(self::TABLES, self::INDEXES);
    }
}
