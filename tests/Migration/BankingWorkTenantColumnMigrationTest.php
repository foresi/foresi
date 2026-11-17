<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Instance\Infrastructure\Doctrine\TenantIdType;
use App\Party\Infrastructure\Doctrine\VatIdType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
 * The tenant column leaves the banking and work tables with their rows kept,
 * and the unique indexes that led with it hold on their remaining columns. A scratch database on SQLite, or a schema of its own in
 * the test database on PostgreSQL.
 */
final class BankingWorkTenantColumnMigrationTest extends TestCase
{
    private const string BEFORE = \DoctrineMigrations\Version20261118000002::class;
    private const string DROP = \DoctrineMigrations\Version20261118000003::class;
    private const string SCHEMA = 'banking_work_tenant_column_migration_test';
    private const string TENANT = '11111111-1111-1111-1111-111111111111';

    private const array TABLES = [
        'bank_feed', 'bank_movement', 'firm_bank_pairing', 'inbound_message',
        'comment_mention', 'label', 'notification', 'notification_preference', 'project', 'project_section',
        'project_status', 'task', 'task_comment', 'task_label', 'task_watcher', 'time_entry', 'work_share',
    ];

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
    }

    protected function tearDown(): void
    {
        if ($this->db->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->db->executeStatement('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
        }
        $this->db->close();
    }

    public function testTheColumnGoesAndTheRowsStay(): void
    {
        $this->label('22222222-2222-2222-2222-222222222222', 'Urgent', true);
        $this->label('33333333-3333-3333-3333-333333333333', 'Later', true);

        $this->migrateTo(self::DROP);

        $tables = $this->db->createSchemaManager()->introspectSchema();
        foreach (self::TABLES as $table) {
            self::assertFalse($tables->getTable($table)->hasColumn('tenant_id'), $table);
        }
        self::assertSame(['Later', 'Urgent'], $this->db->fetchFirstColumn('SELECT name FROM label ORDER BY name'));
        $task = $tables->getTable('task');
        // PostgreSQL quotes the reserved word.
        self::assertSame(['project_id', 'position'], array_map(static fn (string $column): string => trim($column, '"'), $task->getIndex('idx_task_project_position')->getColumns()));
        self::assertSame(['responsible_id', 'due_on'], $task->getIndex('idx_task_responsible_due')->getColumns());
        self::assertSame(['deleted_at'], $task->getIndex('idx_task_deleted')->getColumns());
        self::assertSame(['dedup_key'], $tables->getTable('inbound_message')->getIndex('uniq_inbound_message_dedup')->getColumns());
    }

    public function testTheNameStaysUniqueWithoutTheTenant(): void
    {
        $this->migrateTo(self::DROP);
        $this->label('22222222-2222-2222-2222-222222222222', 'Urgent', false);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->label('33333333-3333-3333-3333-333333333333', 'Urgent', false);
    }

    public function testEmptyTablesGoBackDownWithTheColumn(): void
    {
        $this->migrateTo(self::DROP);

        $this->migrateTo(self::BEFORE);

        $tables = $this->db->createSchemaManager()->introspectSchema();
        foreach (self::TABLES as $table) {
            self::assertTrue($tables->getTable($table)->getColumn('tenant_id')->getNotnull(), $table);
        }
        self::assertSame(['tenant_id', 'name'], $tables->getTable('label')->getIndex('uniq_label_name')->getColumns());
        self::assertSame(['tenant_id', 'dedup_key'], $tables->getTable('inbound_message')->getIndex('uniq_inbound_message_dedup')->getColumns());
    }

    public function testRowsStopTheWayBackDown(): void
    {
        $this->label('22222222-2222-2222-2222-222222222222', 'Urgent', true);
        $this->migrateTo(self::DROP);

        try {
            $this->migrateTo(self::BEFORE);
            self::fail('Rows without a tenant must stop the way down.');
        } catch (AbortMigration $e) {
            self::assertStringContainsString('label holds rows', $e->getMessage());
        }

        self::assertSame(['Urgent'], $this->db->fetchFirstColumn('SELECT name FROM label'));
    }

    private function label(string $id, string $name, bool $withTenant): void
    {
        $this->db->insert('label', ['id' => $id, 'name' => $name, 'colour' => 'red'] + ($withTenant ? ['tenant_id' => self::TENANT] : []));
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
