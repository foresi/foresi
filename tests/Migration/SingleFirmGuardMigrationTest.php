<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Migration;

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
use Foresi\Party\Infrastructure\Doctrine\VatIdType;
use PHPUnit\Framework\TestCase;

/**
 * The guard before the per-firm data goes: no firm or one passes, two stop the
 * upgrade with nothing changed, an archived one counting too. A scratch
 * database on SQLite, or a schema of its own in the test database on PostgreSQL.
 */
final class SingleFirmGuardMigrationTest extends TestCase
{
    private const string BEFORE = 'DoctrineMigrations\Version20261118200001';
    private const string GUARD = 'DoctrineMigrations\Version20261118210001';
    private const string SCHEMA = 'single_firm_guard_migration_test';

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

    public function testAFreshDatabaseWithoutAFirmPasses(): void
    {
        $this->migrateTo(self::GUARD);

        self::assertTrue($this->executed(self::GUARD));
    }

    public function testOneFirmPasses(): void
    {
        $this->firm('11111111-1111-7111-8111-111111111111', '12345679', null);

        $this->migrateTo(self::GUARD);

        self::assertTrue($this->executed(self::GUARD));
    }

    public function testTwoFirmsStopTheUpgrade(): void
    {
        $this->firm('11111111-1111-7111-8111-111111111111', '12345679', null);
        $this->firm('22222222-2222-7222-8222-222222222222', '25596641', null);

        $this->expectTheGuardToStop();
    }

    public function testAnArchivedFirmCountsToo(): void
    {
        $this->firm('11111111-1111-7111-8111-111111111111', '12345679', null);
        $this->firm('22222222-2222-7222-8222-222222222222', '25596641', '2026-11-01 12:00:00');

        $this->expectTheGuardToStop();
    }

    public function testGoingDownChangesNothing(): void
    {
        $this->firm('11111111-1111-7111-8111-111111111111', '12345679', null);
        $this->migrateTo(self::GUARD);

        $this->migrateTo(self::BEFORE);

        self::assertFalse($this->executed(self::GUARD));
        self::assertSame(1, $this->firms());
    }

    private function expectTheGuardToStop(): void
    {
        try {
            $this->migrateTo(self::GUARD);
            self::fail('Two firms must stop the migration.');
        } catch (AbortMigration $e) {
            self::assertStringContainsString('2 firms', $e->getMessage());
            self::assertStringContainsString('ADR 0039', $e->getMessage());
        }

        self::assertFalse($this->executed(self::GUARD), 'A stopped guard is not recorded, so it runs again.');
        self::assertSame(2, $this->firms());
    }

    private function firm(string $id, string $number, ?string $archivedAt): void
    {
        $this->db->insert('legal_entity', [
            'id' => $id,
            'name' => 'Example '.$id[0],
            'registration_country' => 'CZ',
            'registration_number' => $number,
            'active_registration_key' => null === $archivedAt ? $number : null,
            'archived_at' => $archivedAt,
            'address_street' => 'Example Street 1',
            'address_city' => 'Example City',
            'address_postal_code' => '100 00',
            'address_country_code' => 'CZ',
            'vat_status' => 'not_registered',
            'register_entry' => '',
        ]);
    }

    private function firms(): int
    {
        $count = $this->db->fetchOne('SELECT COUNT(*) FROM legal_entity');
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
