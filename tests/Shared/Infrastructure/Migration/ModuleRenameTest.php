<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Foresi\Shared\Infrastructure\Migration\ModuleRename;
use PHPUnit\Framework\TestCase;

/**
 * The steps that give a module's stored names its new id: tables with their
 * rows and indexes, events and the endpoints' subscriptions, the keys of
 * saved views; each the way back too, and none on a table that is not there.
 */
final class ModuleRenameTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public function testATableKeepsItsRowsAndItsIndexesTakeTheNewNames(): void
    {
        $this->connection->executeStatement('CREATE TABLE sales_stage (id TEXT PRIMARY KEY, stage_key TEXT)');
        $this->connection->executeStatement('CREATE UNIQUE INDEX uniq_sales_stage_key ON sales_stage (stage_key)');
        $this->connection->insert('sales_stage', ['id' => '1', 'stage_key' => 'inquiry']);

        $this->rename()->renameTables(['sales_stage' => 'deal_stage'], ['uniq_sales_stage_key' => ['deal_stage', 'uniq_deal_stage_key', ['stage_key'], true]]);

        self::assertSame(['deal_stage'], $this->connection->createSchemaManager()->listTableNames());
        self::assertSame(['inquiry'], $this->connection->fetchFirstColumn('SELECT stage_key FROM deal_stage'));
        self::assertSame(['uniq_deal_stage_key'], $this->indexes('deal_stage'));
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->connection->insert('deal_stage', ['id' => '2', 'stage_key' => 'inquiry']);
    }

    public function testTheTablesGoBackToTheirOldNames(): void
    {
        $this->connection->executeStatement('CREATE TABLE sales_deal (id TEXT PRIMARY KEY, stage_id TEXT)');
        $this->connection->executeStatement('CREATE INDEX idx_sales_deal_stage ON sales_deal (stage_id)');
        $tables = ['sales_deal' => 'deal'];
        $indexes = ['idx_sales_deal_stage' => ['deal', 'idx_deal_stage', ['stage_id'], false]];

        $this->rename()->renameTables($tables, $indexes);
        $this->rename()->renameTablesBack($tables, $indexes);

        self::assertSame(['sales_deal'], $this->connection->createSchemaManager()->listTableNames());
        self::assertSame(['idx_sales_deal_stage'], $this->indexes('sales_deal'));
    }

    public function testATableThatIsNotThereIsLeftAlone(): void
    {
        $this->rename()->renameTables(['sales_deal' => 'deal'], ['idx_sales_deal_stage' => ['deal', 'idx_deal_stage', ['stage_id'], false]]);
        $this->rename()->renameEvents('sales.', 'deals.');
        $this->rename()->renameListKeys(['sales.deals' => 'deals.deals']);

        self::assertSame([], $this->connection->createSchemaManager()->listTableNames());
    }

    public function testTheEventsAndTheSubscriptionsTakeTheNewPrefix(): void
    {
        $this->events();

        $this->rename()->renameEvents('sales.', 'deals.');

        self::assertSame(['deals.deal.created', 'deals.billing_entry.created', 'salesforce.lead.created', 'work.task.created'], $this->connection->fetchFirstColumn('SELECT name FROM event_log ORDER BY id'));
        self::assertSame(['deals.deal.won', 'work.task.created'], $this->connection->fetchFirstColumn('SELECT event_type FROM webhook_delivery ORDER BY id'));
        self::assertSame(['{"type":"sales.deal.won"}', '{}'], $this->connection->fetchFirstColumn('SELECT body FROM webhook_delivery ORDER BY id'), 'A delivery keeps the body it was sent with.');
        self::assertSame([
            '["deals.*","work.task.created"]',
            '["deals.deal.won","deals.deal.lost"]',
            '["*"]',
            '{"not":"a list"}',
        ], $this->connection->fetchFirstColumn('SELECT event_types FROM webhook_endpoint ORDER BY id'));
    }

    public function testTheEventsGoBack(): void
    {
        $this->events();

        $this->rename()->renameEvents('sales.', 'deals.');
        $this->rename()->renameEvents('deals.', 'sales.');

        self::assertSame(['sales.deal.created', 'sales.billing_entry.created', 'salesforce.lead.created', 'work.task.created'], $this->connection->fetchFirstColumn('SELECT name FROM event_log ORDER BY id'));
        self::assertSame(['["sales.*","work.task.created"]', '["sales.deal.won","sales.deal.lost"]', '["*"]', '{"not":"a list"}'], $this->connection->fetchFirstColumn('SELECT event_types FROM webhook_endpoint ORDER BY id'));
    }

    public function testTheSavedViewsOfAListTakeItsNewKey(): void
    {
        $this->connection->executeStatement('CREATE TABLE saved_view (id TEXT, list_key TEXT, system_key TEXT)');
        $this->connection->insert('saved_view', ['id' => '1', 'list_key' => 'sales.deals', 'system_key' => 'sales.deals.open']);
        $this->connection->insert('saved_view', ['id' => '2', 'list_key' => 'sales.deals', 'system_key' => null]);
        $this->connection->insert('saved_view', ['id' => '3', 'list_key' => 'sales.deal_stages', 'system_key' => 'sales.deal_stages.all']);
        $this->connection->insert('saved_view', ['id' => '4', 'list_key' => 'work.tasks', 'system_key' => 'work.tasks.all']);

        $this->rename()->renameListKeys(['sales.deals' => 'deals.deals']);

        self::assertSame([
            ['deals.deals', 'deals.deals.open'],
            ['deals.deals', null],
            ['sales.deal_stages', 'sales.deal_stages.all'],
            ['work.tasks', 'work.tasks.all'],
        ], array_map(array_values(...), $this->connection->fetchAllAssociative('SELECT list_key, system_key FROM saved_view ORDER BY id')));
    }

    private function events(): void
    {
        $this->connection->executeStatement('CREATE TABLE event_log (id TEXT, name TEXT)');
        $this->connection->executeStatement('CREATE TABLE webhook_delivery (id TEXT, event_type TEXT, body TEXT)');
        $this->connection->executeStatement('CREATE TABLE webhook_endpoint (id TEXT, event_types TEXT)');
        foreach (['sales.deal.created', 'sales.billing_entry.created', 'salesforce.lead.created', 'work.task.created'] as $id => $name) {
            $this->connection->insert('event_log', ['id' => (string) $id, 'name' => $name]);
        }
        $this->connection->insert('webhook_delivery', ['id' => '1', 'event_type' => 'sales.deal.won', 'body' => '{"type":"sales.deal.won"}']);
        $this->connection->insert('webhook_delivery', ['id' => '2', 'event_type' => 'work.task.created', 'body' => '{}']);
        $this->connection->insert('webhook_endpoint', ['id' => '1', 'event_types' => '["sales.*","work.task.created"]']);
        $this->connection->insert('webhook_endpoint', ['id' => '2', 'event_types' => '["sales.deal.won","sales.deal.lost"]']);
        $this->connection->insert('webhook_endpoint', ['id' => '3', 'event_types' => '["*"]']);
        $this->connection->insert('webhook_endpoint', ['id' => '4', 'event_types' => '{"not":"a list"}']);
    }

    private function rename(): ModuleRename
    {
        return new ModuleRename($this->connection);
    }

    /**
     * @return list<string>
     */
    private function indexes(string $table): array
    {
        $names = [];
        foreach ($this->connection->fetchFirstColumn('SELECT name FROM sqlite_master WHERE type = ? AND tbl_name = ? AND sql IS NOT NULL ORDER BY name', ['index', $table]) as $name) {
            $names[] = \is_string($name) ? $name : '';
        }

        return $names;
    }
}
