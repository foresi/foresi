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
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\TestCase;

/**
 * Names the recurring templates made before names existed and keeps whom they
 * sent to: a scratch database on SQLite, or a schema of its own in the test
 * database on PostgreSQL. The rows hold only what the columns require.
 */
final class RecurringNameMigrationTest extends TestCase
{
    private const string BEFORE = 'DoctrineMigrations\Version20261103000804';
    /** The migration under test, not the latest: later ones expect rows this test does not fill. */
    private const string AFTER = 'DoctrineMigrations\Version20261103000805';
    private const string TENANT = '11111111-1111-1111-1111-111111111111';
    private const string SCHEMA = 'recurring_name_migration_test';

    private Connection $db;

    /** @var array<string, string> */
    private array $ids = [];

    protected function setUp(): void
    {
        foreach (['tenant_id' => TenantIdType::class, 'vat_id' => VatIdType::class] as $name => $class) {
            if (!Type::hasType($name)) {
                Type::addType($name, $class);
            }
        }
        $this->db = $this->connect();
        $this->migrateTo(self::BEFORE);
        $this->seed();
    }

    protected function tearDown(): void
    {
        if ($this->db->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->db->executeStatement('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
        }
        $this->db->close();
    }

    public function testATemplateIsNamedAfterItsDescriptionElseItsCustomer(): void
    {
        $this->migrateTo(self::AFTER);

        self::assertSame('Hosting {month/year}', $this->column('described', 'name'));
        self::assertSame('Example Customer a.s.', $this->column('blank', 'name'), 'no description: the customer');
        self::assertSame(120, mb_strlen($this->column('long', 'name')), 'cut to the column');
    }

    public function testBothCopiesStayOnAndTheDeliveryIsKept(): void
    {
        $this->migrateTo(self::AFTER);

        foreach (['described' => 'send', 'blank' => 'review'] as $label => $delivery) {
            self::assertTrue((bool) $this->column($label, 'copy_to_me'));
            self::assertTrue((bool) $this->column($label, 'copy_to_others'));
            self::assertSame($delivery, $this->column($label, 'delivery'), 'send is "To the customer" ticked, review unticked');
        }
    }

    public function testItCanBeRolledBack(): void
    {
        $this->migrateTo(self::AFTER);
        $this->migrateTo(self::BEFORE);

        $columns = array_keys($this->db->createSchemaManager()->introspectTableColumnsByUnquotedName('recurring_invoice'));
        self::assertNotContains('name', $columns);
        self::assertNotContains('copy_to_me', $columns);
        self::assertNotContains('copy_to_others', $columns);
    }

    private function column(string $label, string $column): string
    {
        $value = $this->db->fetchOne(\sprintf('SELECT %s FROM recurring_invoice WHERE id = ?', $column), [$this->ids[$label]]);
        self::assertIsScalar($value, $label);

        return (string) $value;
    }

    private function seed(): void
    {
        foreach (['firm', 'customer', 'described', 'blank', 'long'] as $key) {
            $this->ids[$key] = Id::generate();
        }
        $this->db->insert('tenant', ['id' => self::TENANT, 'name' => 'Example', 'created_at' => '2026-11-01 12:00:00']);
        $this->row('legal_entity', ['id' => $this->ids['firm'], 'name' => 'Example Firm s.r.o.']);
        $this->row('party', ['id' => $this->ids['customer'], 'kind' => 'company', 'display_name' => 'Example Customer a.s.', 'roles' => '[]']);
        $this->template('described', "  Hosting {month/year}\n", 'send');
        $this->template('blank', '', 'review');
        $this->template('long', str_repeat('Long description ', 20), 'send');
    }

    private function template(string $label, string $description, string $delivery): void
    {
        $this->row('recurring_invoice', [
            'id' => $this->ids[$label], 'legal_entity_id' => $this->ids['firm'], 'customer_id' => $this->ids['customer'],
            'description' => $description, 'delivery' => $delivery, 'state' => 'active',
        ]);
    }

    /**
     * Inserts a row of the tenant, with the given values and a plain one of its type
     * in every other column that takes no null and has no default.
     *
     * @param non-empty-string     $table
     * @param array<string, mixed> $given
     */
    private function row(string $table, array $given): void
    {
        $row = ['tenant_id' => self::TENANT] + $given;
        foreach ($this->db->createSchemaManager()->introspectTableColumnsByUnquotedName($table) as $column) {
            $name = $column->getObjectName()->getIdentifier()->getValue();
            if (\array_key_exists($name, $row) || !$column->getNotnull() || null !== $column->getDefault() || $column->getAutoincrement()) {
                continue;
            }
            $row[$name] = match (Type::getTypeRegistry()->lookupName($column->getType())) {
                Types::BOOLEAN => false,
                Types::INTEGER, Types::SMALLINT, Types::BIGINT => 0,
                Types::DATE_IMMUTABLE, Types::DATE_MUTABLE => '2026-11-01',
                Types::DATETIME_IMMUTABLE, Types::DATETIME_MUTABLE => '2026-11-01 12:00:00',
                Types::GUID => Id::generate(),
                Types::JSON => '[]',
                default => mb_substr('x', 0, max(1, (int) $column->getLength())),
            };
        }
        $quoted = [];
        $types = [];
        foreach ($row as $column => $value) {
            $name = $this->db->quoteSingleIdentifier($column);
            $quoted[$name] = $value;
            $types[$name] = \is_bool($value) ? ParameterType::BOOLEAN : (\is_int($value) ? ParameterType::INTEGER : ParameterType::STRING);
        }
        $this->db->insert($table, $quoted, $types);
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
