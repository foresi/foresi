<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Migration;

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
 * The scheduled job runs lose their tenant column with their rows kept, the
 * unique index that led with it holds on the job alone, and the tenant table
 * goes. A scratch database on SQLite, or a schema of its own in the test
 * database on PostgreSQL.
 */
final class InstanceTenantRemovalMigrationTest extends TestCase
{
    private const string BEFORE = 'DoctrineMigrations\Version20261118000006';
    private const string DROP = 'DoctrineMigrations\Version20261118000007';
    private const string SCHEMA = 'instance_tenant_removal_migration_test';
    private const string TENANT = '11111111-1111-1111-1111-111111111111';

    private Connection $db;

    protected function setUp(): void
    {
        if (!Type::hasType('vat_id')) {
            Type::addType('vat_id', VatIdType::class);
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

    public function testTheColumnAndTheTenantTableGoAndTheRowsStay(): void
    {
        $this->tenant();
        $this->jobRun('11111111-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'recurring-invoices');
        $this->jobRun('22222222-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'reminders');

        $this->migrateTo(self::DROP);

        $schema = $this->db->createSchemaManager()->introspectSchema();
        self::assertFalse($schema->hasTable('tenant'));
        self::assertFalse($schema->getTable('scheduled_job_run')->hasColumn('tenant_id'));
        self::assertSame(['recurring-invoices', 'reminders'], $this->db->fetchFirstColumn('SELECT job FROM scheduled_job_run ORDER BY job'));
        self::assertSame('2026-11-01', $this->db->fetchOne("SELECT last_period FROM scheduled_job_run WHERE job = 'reminders'"));
    }

    public function testTheJobStaysUniqueWithoutTheTenant(): void
    {
        $this->migrateTo(self::DROP);
        $this->db->insert('scheduled_job_run', ['id' => '11111111-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'job' => 'reminders']);

        $index = $this->db->createSchemaManager()->introspectSchema()->getTable('scheduled_job_run')->getIndex('uniq_scheduled_job_run');
        self::assertSame(['job'], $index->getColumns());
        self::assertTrue($index->isUnique());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->db->insert('scheduled_job_run', ['id' => '22222222-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'job' => 'reminders']);
    }

    public function testEmptyRunsGoBackDownWithTheTenantTableAndColumn(): void
    {
        $this->migrateTo(self::DROP);

        $this->migrateTo(self::BEFORE);

        $schema = $this->db->createSchemaManager()->introspectSchema();
        self::assertTrue($schema->hasTable('tenant'));
        $runs = $schema->getTable('scheduled_job_run');
        self::assertTrue($runs->getColumn('tenant_id')->getNotnull());
        self::assertSame(['tenant_id', 'job'], $runs->getIndex('uniq_scheduled_job_run')->getColumns());
    }

    public function testRowsStopTheWayBackDown(): void
    {
        $this->tenant();
        $this->jobRun('11111111-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'reminders');
        $this->migrateTo(self::DROP);

        try {
            $this->migrateTo(self::BEFORE);
            self::fail('Rows without a tenant must stop the way down.');
        } catch (AbortMigration $e) {
            self::assertStringContainsString('scheduled job runs hold rows', $e->getMessage());
        }

        self::assertSame(['reminders'], $this->db->fetchFirstColumn('SELECT job FROM scheduled_job_run'));
    }

    private function tenant(): void
    {
        $this->db->insert('tenant', ['id' => self::TENANT, 'name' => 'Example Company', 'created_at' => '2026-10-01 00:00:00']);
    }

    private function jobRun(string $id, string $job): void
    {
        $this->db->insert('scheduled_job_run', ['id' => $id, 'tenant_id' => self::TENANT, 'job' => $job, 'last_period' => '2026-11-01']);
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
