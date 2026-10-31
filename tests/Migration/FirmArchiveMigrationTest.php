<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Party\Infrastructure\Doctrine\VatIdType;
use App\Shared\Domain\Id;
use App\Tenancy\Infrastructure\Doctrine\TenantIdType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Type;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\TestCase;

/**
 * Existing firms stay active and hold their number: a scratch database on
 * SQLite, or a schema of its own in the test database on PostgreSQL.
 */
final class FirmArchiveMigrationTest extends TestCase
{
    private const string BEFORE = 'DoctrineMigrations\Version20261101000002';
    private const string AFTER = 'DoctrineMigrations\Version20261101000003';
    private const string TENANT = '11111111-1111-1111-1111-111111111111';
    private const string SCHEMA = 'firm_archive_migration_test';

    private Connection $db;

    protected function setUp(): void
    {
        foreach (['tenant_id' => TenantIdType::class, 'vat_id' => VatIdType::class] as $name => $class) {
            if (!Type::hasType($name)) {
                Type::addType($name, $class);
            }
        }
        $this->db = $this->connect();
        $this->migrateTo(self::BEFORE);
        $this->db->insert('tenant', ['id' => self::TENANT, 'name' => 'Example', 'created_at' => '2026-10-31 12:00:00']);
    }

    protected function tearDown(): void
    {
        if ($this->db->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->db->executeStatement('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
        }
        $this->db->close();
    }

    public function testExistingFirmsStayActiveAndGetTheComparisonFormOfTheirNumber(): void
    {
        $this->firm('hrb 12-34');

        $this->migrateTo(self::AFTER);

        self::assertSame(['HRB1234', null], $this->db->fetchNumeric('SELECT active_registration_key, archived_at FROM legal_entity'));
    }

    public function testTheIndexRefusesASecondActiveFirmAndIgnoresArchivedOnes(): void
    {
        $this->firm('12345695');
        $this->migrateTo(self::AFTER);

        $this->firm('12345695', ['archived_at' => '2026-10-31 12:00:00']);
        $this->firm('12345695', ['archived_at' => '2026-10-31 12:00:00']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->firm('12345695', ['active_registration_key' => '12345695']);
    }

    public function testItCanBeRolledBack(): void
    {
        $this->firm('12345695');
        $this->migrateTo(self::AFTER);

        $this->migrateTo(self::BEFORE);

        $columns = array_keys($this->db->createSchemaManager()->introspectTableColumnsByUnquotedName('legal_entity'));
        self::assertNotContains('archived_at', $columns);
        self::assertNotContains('active_registration_key', $columns);
        self::assertSame(['Example Firm'], $this->db->fetchFirstColumn('SELECT name FROM legal_entity'));
    }

    /**
     * @param array<string, string> $extra
     */
    private function firm(string $number, array $extra = []): void
    {
        $this->db->insert('legal_entity', $extra + [
            'id' => Id::generate(),
            'tenant_id' => self::TENANT,
            'name' => 'Example Firm',
            'vat_status' => 'not_registered',
            'register_entry' => '',
            'bank_accounts' => '[]',
            'registration_country' => 'CZ',
            'registration_number' => $number,
            'address_street' => 'Example Street 1',
            'address_city' => 'Example City',
            'address_postal_code' => '100 00',
            'address_country_code' => 'CZ',
        ]);
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
