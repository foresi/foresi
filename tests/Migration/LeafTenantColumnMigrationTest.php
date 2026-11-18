<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Migration;

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
use Foresi\Party\Infrastructure\Doctrine\VatIdType;
use PHPUnit\Framework\TestCase;

/**
 * The tenant column leaves the cash flow, purchasing, timesheet and iDoklad
 * tables with their rows kept, and the unique index that led with it holds on
 * its remaining columns. A scratch database on SQLite, or a schema of its own in
 * the test database on PostgreSQL.
 */
final class LeafTenantColumnMigrationTest extends TestCase
{
    private const string BEFORE = 'DoctrineMigrations\Version20261117210001';
    private const string DROP = 'DoctrineMigrations\Version20261118000001';
    private const string SCHEMA = 'leaf_tenant_column_migration_test';
    private const string TENANT = '11111111-1111-1111-1111-111111111111';

    private const array TABLES = [
        'cash_flow_checkpoint', 'cash_flow_expected_date', 'cash_flow_minimum_balance', 'cash_flow_planned_item',
        'evidence_counter', 'evidence_series', 'received_document', 'received_document_payment', 'released_time',
        'idoklad_connection', 'idoklad_import_run', 'idoklad_job', 'idoklad_link', 'idoklad_pull', 'idoklad_record',
    ];

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

    public function testTheColumnGoesAndTheRowsStay(): void
    {
        $this->expectedDate('22222222-2222-2222-2222-222222222222', 'invoice', 'A');
        $this->expectedDate('33333333-3333-3333-3333-333333333333', 'invoice', 'B');

        $this->migrateTo(self::DROP);

        $tables = $this->db->createSchemaManager()->introspectSchema();
        foreach (self::TABLES as $table) {
            self::assertFalse($tables->getTable($table)->hasColumn('tenant_id'), $table);
        }
        self::assertSame(['A', 'B'], $this->db->fetchFirstColumn('SELECT subject_id FROM cash_flow_expected_date ORDER BY subject_id'));
        $received = $tables->getTable('received_document');
        self::assertSame(['legal_entity_id', 'received_date'], $received->getIndex('idx_received_document_firm_received')->getColumns());
        self::assertSame(['legal_entity_id', 'deduction_period'], $received->getIndex('idx_received_document_firm_deduction')->getColumns());
    }

    public function testTheSubjectStaysUniqueWithoutTheTenant(): void
    {
        $this->migrateTo(self::DROP);
        $this->db->insert('cash_flow_expected_date', ['id' => '22222222-2222-2222-2222-222222222222', 'subject_type' => 'invoice', 'subject_id' => 'A', 'expected_on' => '2026-11-30']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->db->insert('cash_flow_expected_date', ['id' => '33333333-3333-3333-3333-333333333333', 'subject_type' => 'invoice', 'subject_id' => 'A', 'expected_on' => '2026-12-31']);
    }

    public function testEmptyTablesGoBackDownWithTheColumn(): void
    {
        $this->migrateTo(self::DROP);

        $this->migrateTo(self::BEFORE);

        $tables = $this->db->createSchemaManager()->introspectSchema();
        foreach (self::TABLES as $table) {
            self::assertTrue($tables->getTable($table)->getColumn('tenant_id')->getNotnull(), $table);
        }
        self::assertSame(['tenant_id', 'subject_type', 'subject_id'], $tables->getTable('cash_flow_expected_date')->getIndex('uniq_cash_flow_expected_date_subject')->getColumns());
    }

    public function testRowsStopTheWayBackDown(): void
    {
        $this->expectedDate('22222222-2222-2222-2222-222222222222', 'invoice', 'A');
        $this->migrateTo(self::DROP);

        try {
            $this->migrateTo(self::BEFORE);
            self::fail('Rows without a tenant must stop the way down.');
        } catch (AbortMigration $e) {
            self::assertStringContainsString('cash_flow_expected_date holds rows', $e->getMessage());
        }

        self::assertSame(['A'], $this->db->fetchFirstColumn('SELECT subject_id FROM cash_flow_expected_date'));
    }

    private function expectedDate(string $id, string $type, string $subject): void
    {
        $this->db->insert('cash_flow_expected_date', ['id' => $id, 'tenant_id' => self::TENANT, 'subject_type' => $type, 'subject_id' => $subject, 'expected_on' => '2026-11-30']);
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
