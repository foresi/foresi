<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Deals\Infrastructure\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Foresi\Deals\Migrations\Version20261127150000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The deals' tables, events, webhook subscriptions and lists take the name
 * deals, rows and all; down names them sales again.
 */
final class DealNamesMigrationTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        require_once \dirname(__DIR__, 4).'/src/Deals/migrations/Version20261127150000.php';
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE sales_deal (id TEXT PRIMARY KEY, client_party_id TEXT, stage_id TEXT)');
        $this->connection->executeStatement('CREATE INDEX idx_sales_deal_client ON sales_deal (client_party_id)');
        $this->connection->executeStatement('CREATE INDEX idx_sales_deal_stage ON sales_deal (stage_id)');
        $this->connection->insert('sales_deal', ['id' => 'd1', 'client_party_id' => 'c1', 'stage_id' => 's1']);
        $this->connection->executeStatement('CREATE TABLE event_log (id TEXT, name TEXT)');
        $this->connection->insert('event_log', ['id' => '1', 'name' => 'sales.deal.won']);
        $this->connection->executeStatement('CREATE TABLE webhook_delivery (id TEXT, event_type TEXT)');
        $this->connection->executeStatement('CREATE TABLE webhook_endpoint (id TEXT, event_types TEXT)');
        $this->connection->insert('webhook_endpoint', ['id' => '1', 'event_types' => '["sales.*","contacts.contact.created"]']);
        $this->connection->insert('webhook_endpoint', ['id' => '2', 'event_types' => '["sales.billing_entry.created"]']);
        $this->connection->executeStatement('CREATE TABLE saved_view (id TEXT, list_key TEXT, system_key TEXT)');
        $this->connection->insert('saved_view', ['id' => '1', 'list_key' => 'sales.deals', 'system_key' => 'sales.deals.won']);
        $this->connection->insert('saved_view', ['id' => '2', 'list_key' => 'sales.lost_reasons', 'system_key' => 'sales.lost_reasons.all']);
    }

    public function testTheDealsTakeTheNameOfTheirModule(): void
    {
        $this->migration()->postUp(new Schema());

        self::assertSame(['deal', 'event_log', 'saved_view', 'webhook_delivery', 'webhook_endpoint'], $this->connection->createSchemaManager()->listTableNames());
        self::assertSame(['d1'], $this->connection->fetchFirstColumn('SELECT id FROM deal'));
        self::assertSame(['idx_deal_client', 'idx_deal_stage'], $this->connection->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'deal' AND sql IS NOT NULL ORDER BY name"));
        self::assertSame(['deals.deal.won'], $this->connection->fetchFirstColumn('SELECT name FROM event_log'));
        self::assertSame(['["deals.*","contacts.contact.created"]', '["deals.billing_entry.created"]'], $this->connection->fetchFirstColumn('SELECT event_types FROM webhook_endpoint ORDER BY id'));
        self::assertSame([['deals.deals', 'deals.deals.won'], ['deals.lost_reasons', 'deals.lost_reasons.all']], array_map(array_values(...), $this->connection->fetchAllAssociative('SELECT list_key, system_key FROM saved_view ORDER BY id')));
    }

    public function testDownNamesThemSalesAgain(): void
    {
        $this->migration()->postUp(new Schema());
        $this->migration()->postDown(new Schema());

        self::assertContains('sales_deal', $this->connection->createSchemaManager()->listTableNames());
        self::assertSame(['idx_sales_deal_client', 'idx_sales_deal_stage'], $this->connection->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'sales_deal' AND sql IS NOT NULL ORDER BY name"));
        self::assertSame(['sales.deal.won'], $this->connection->fetchFirstColumn('SELECT name FROM event_log'));
        self::assertSame(['["sales.*","contacts.contact.created"]', '["sales.billing_entry.created"]'], $this->connection->fetchFirstColumn('SELECT event_types FROM webhook_endpoint ORDER BY id'));
        self::assertSame(['sales.deals', 'sales.lost_reasons'], $this->connection->fetchFirstColumn('SELECT list_key FROM saved_view ORDER BY id'));
    }

    public function testItSaysWhichTablesItRenames(): void
    {
        self::assertSame('deal', $this->migration()->renamedTables()['sales_deal']);
        self::assertSame('deal_billing_plan_entry', $this->migration()->renamedTables()['sales_billing_plan_entry']);
    }

    private function migration(): Version20261127150000
    {
        return new Version20261127150000($this->connection, new NullLogger());
    }
}
