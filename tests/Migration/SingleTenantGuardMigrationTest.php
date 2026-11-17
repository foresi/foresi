<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Party\Infrastructure\Doctrine\VatIdType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Type;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\TestCase;

/**
 * The guard before the tenant columns go: no tenant or one passes, two stop the
 * upgrade with nothing changed. A scratch database on SQLite, or a schema of
 * its own in the test database on PostgreSQL.
 */
final class SingleTenantGuardMigrationTest extends TestCase
{
    private const string BEFORE = 'DoctrineMigrations\Version20261117200001';
    private const string GUARD = 'DoctrineMigrations\Version20261117210001';
    private const string SCHEMA = 'single_tenant_guard_migration_test';

    private Connection $db;

    protected function setUp(): void
    {
        foreach (['vat_id' => VatIdType::class] as $name => $class) {
            if (!Type::hasType($name)) {
                Type::addType($name, $class);
            }
        }
        $this->db = $this->connect();
        $this->migrateTo(self::BEFORE);
    }

    protected function tearDown(): void
    {
        if ($this->db->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->db->executeStatement('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
        }
        $this->db->close();
    }

    public function testAFreshDatabaseWithoutATenantPasses(): void
    {
        $this->migrateTo(self::GUARD);

        self::assertTrue($this->executed(self::GUARD));
    }

    public function testOneTenantPasses(): void
    {
        $this->tenant('11111111-1111-1111-1111-111111111111');

        $this->migrateTo(self::GUARD);

        self::assertTrue($this->executed(self::GUARD));
    }

    public function testTwoTenantsStopTheUpgrade(): void
    {
        $this->tenant('11111111-1111-1111-1111-111111111111');
        $this->tenant('22222222-2222-2222-2222-222222222222');

        try {
            $this->migrateTo(self::GUARD);
            self::fail('Two tenants must stop the migration.');
        } catch (AbortMigration $e) {
            self::assertStringContainsString('2 tenants', $e->getMessage());
            self::assertStringContainsString('ADR 0035', $e->getMessage());
        }

        self::assertFalse($this->executed(self::GUARD), 'A stopped guard is not recorded, so it runs again.');
        self::assertSame(2, $this->tenants());
    }

    public function testGoingDownChangesNothing(): void
    {
        $this->tenant('11111111-1111-1111-1111-111111111111');
        $this->migrateTo(self::GUARD);

        $this->migrateTo(self::BEFORE);

        self::assertFalse($this->executed(self::GUARD));
        self::assertSame(1, $this->tenants());
    }

    private function tenant(string $id): void
    {
        $this->db->insert('tenant', ['id' => $id, 'name' => 'Example '.$id[0], 'created_at' => '2026-11-01 12:00:00']);
    }

    private function tenants(): int
    {
        $count = $this->db->fetchOne('SELECT COUNT(*) FROM tenant');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function executed(string $version): bool
    {
        return false !== $this->db->fetchOne('SELECT version FROM doctrine_migration_versions WHERE version = ?', [$version]);
    }

    private function connect(): Connection
    {
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? '';
        if (!\is_string($url) || !str_starts_with($url, 'postgres')) {
            return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        }

        $params = new DsnParser(['postgres' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql'])->parse($url);
        unset($params['serverVersion'], $params['charset']);
        $connection = DriverManager::getConnection($params);
        $connection->executeStatement('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
        $connection->executeStatement('CREATE SCHEMA '.self::SCHEMA);
        $connection->executeStatement('SET search_path TO '.self::SCHEMA);

        return $connection;
    }

    private function migrateTo(string $version): void
    {
        $migrations = DependencyFactory::fromConnection(
            new ConfigurationArray(['migrations_paths' => ['DoctrineMigrations' => \dirname(__DIR__, 2).'/migrations']]),
            new ExistingConnection($this->db),
        );
        $migrations->getMetadataStorage()->ensureInitialized();
        $plan = $migrations->getMigrationPlanCalculator()->getPlanUntilVersion(new Version($version));
        $migrations->getMigrator()->migrate($plan, new MigratorConfiguration());
    }
}
