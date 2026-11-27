<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Infrastructure\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Foresi\Identity\Migrations\Version20261127150000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The tokens and the authorization codes hold deals instead of sales, and a
 * token that read or changed contacts reads or changes the firm too; down
 * gives the old scopes back.
 */
final class ScopeRenameMigrationTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        require_once \dirname(__DIR__, 4).'/src/Identity/migrations/Version20261127150000.php';
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (['api_token', 'oauth_authorization_code'] as $table) {
            $this->connection->executeStatement(\sprintf('CREATE TABLE %s (id TEXT, scopes TEXT)', $table));
        }
        $this->connection->insert('api_token', ['id' => '1', 'scopes' => '["sales:read","invoices:write"]']);
        $this->connection->insert('api_token', ['id' => '2', 'scopes' => '["contacts:read"]']);
        $this->connection->insert('api_token', ['id' => '3', 'scopes' => '["contacts:write","sales:write"]']);
        $this->connection->insert('api_token', ['id' => '4', 'scopes' => '["read"]']);
        $this->connection->insert('oauth_authorization_code', ['id' => '1', 'scopes' => '["sales:write","contacts:read"]']);
    }

    public function testSalesBecomesDealsAndContactsGrantsTheFirm(): void
    {
        $this->migration()->postUp(new Schema());

        self::assertSame([
            '["deals:read","invoices:write"]',
            '["contacts:read","firm:read"]',
            '["contacts:write","firm:write","deals:write"]',
            '["read"]',
        ], $this->connection->fetchFirstColumn('SELECT scopes FROM api_token ORDER BY id'));
        self::assertSame(['["deals:write","contacts:read","firm:read"]'], $this->connection->fetchFirstColumn('SELECT scopes FROM oauth_authorization_code'));
    }

    public function testATokenThatHasTheFirmAlreadyHoldsItOnce(): void
    {
        $this->connection->update('api_token', ['scopes' => '["contacts:read","firm:read"]'], ['id' => '2']);

        $this->migration()->postUp(new Schema());

        self::assertSame('["contacts:read","firm:read"]', $this->connection->fetchOne('SELECT scopes FROM api_token WHERE id = ?', ['2']));
    }

    public function testDownGivesTheOldScopesBack(): void
    {
        $this->migration()->postUp(new Schema());
        $this->migration()->postDown(new Schema());

        self::assertSame([
            '["sales:read","invoices:write"]',
            '["contacts:read"]',
            '["contacts:write","sales:write"]',
            '["read"]',
        ], $this->connection->fetchFirstColumn('SELECT scopes FROM api_token ORDER BY id'));
        self::assertSame(['["sales:write","contacts:read"]'], $this->connection->fetchFirstColumn('SELECT scopes FROM oauth_authorization_code'));
    }

    public function testTablesThatAreNotThereAreLeftAlone(): void
    {
        $this->connection->executeStatement('DROP TABLE oauth_authorization_code');

        $this->migration()->postUp(new Schema());

        self::assertSame('["deals:read","invoices:write"]', $this->connection->fetchOne('SELECT scopes FROM api_token WHERE id = ?', ['1']));
    }

    private function migration(): Version20261127150000
    {
        return new Version20261127150000($this->connection, new NullLogger());
    }
}
