<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Infrastructure\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Foresi\Party\Migrations\Version20261127140000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The stored events of a contact take the address book's names, and an endpoint
 * that took every event of Party keeps receiving them; down names them back.
 */
final class ContactEventRenameMigrationTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        require_once \dirname(__DIR__, 4).'/src/Party/migrations/Version20261127140000.php';
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE event_log (id TEXT, name TEXT)');
        $this->connection->executeStatement('CREATE TABLE webhook_delivery (id TEXT, event_type TEXT)');
        $this->connection->executeStatement('CREATE TABLE webhook_endpoint (id TEXT, event_types TEXT)');
        $this->connection->insert('event_log', ['id' => '1', 'name' => 'party.contact.created']);
        $this->connection->insert('event_log', ['id' => '2', 'name' => 'party.firm.vat_status_changed']);
        $this->connection->insert('webhook_delivery', ['id' => '1', 'event_type' => 'party.contact.archived']);
        $this->connection->insert('webhook_endpoint', ['id' => '1', 'event_types' => '["party.contact.updated","work.task.created"]']);
        $this->connection->insert('webhook_endpoint', ['id' => '2', 'event_types' => '["party.*"]']);
    }

    public function testTheContactEventsTakeTheNamesOfTheAddressBook(): void
    {
        $this->migration()->postUp($this->schema());

        self::assertSame(['contacts.contact.created', 'party.firm.vat_status_changed'], $this->connection->fetchFirstColumn('SELECT name FROM event_log ORDER BY id'));
        self::assertSame(['contacts.contact.archived'], $this->connection->fetchFirstColumn('SELECT event_type FROM webhook_delivery'));
        self::assertSame(['["contacts.contact.updated","work.task.created"]', '["party.*","contacts.*"]'], $this->connection->fetchFirstColumn('SELECT event_types FROM webhook_endpoint ORDER BY id'));
    }

    public function testDownNamesThemTheOldWayAgain(): void
    {
        $this->migration()->postUp($this->schema());
        $this->migration()->postDown($this->schema());

        self::assertSame(['party.contact.created', 'party.firm.vat_status_changed'], $this->connection->fetchFirstColumn('SELECT name FROM event_log ORDER BY id'));
        self::assertSame(['party.contact.archived'], $this->connection->fetchFirstColumn('SELECT event_type FROM webhook_delivery'));
        self::assertSame(['["party.contact.updated","work.task.created"]', '["party.*"]'], $this->connection->fetchFirstColumn('SELECT event_types FROM webhook_endpoint ORDER BY id'));
    }

    public function testTablesOfModulesThatAreNotInstalledAreLeftAlone(): void
    {
        $this->migration()->postUp(new Schema());

        self::assertSame(['party.contact.created', 'party.firm.vat_status_changed'], $this->connection->fetchFirstColumn('SELECT name FROM event_log ORDER BY id'));
    }

    private function migration(): Version20261127140000
    {
        return new Version20261127140000($this->connection, new NullLogger());
    }

    private function schema(): Schema
    {
        $schema = new Schema();
        foreach (['event_log', 'webhook_delivery', 'webhook_endpoint'] as $table) {
            $schema->createTable($table);
        }

        return $schema;
    }
}
