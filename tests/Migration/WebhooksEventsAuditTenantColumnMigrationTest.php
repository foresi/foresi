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
 * The tenant column leaves the webhook, event log, audit log and attachment
 * tables with their rows kept, and the indexes that led with it hold on their
 * remaining columns. A scratch database on SQLite, or a schema of its own in the
 * test database on PostgreSQL.
 */
final class WebhooksEventsAuditTenantColumnMigrationTest extends TestCase
{
    private const string BEFORE = 'DoctrineMigrations\Version20261118000001';
    private const string DROP = 'DoctrineMigrations\Version20261118000002';
    private const string SCHEMA = 'webhooks_events_audit_tenant_column_migration_test';
    private const string TENANT = '11111111-1111-1111-1111-111111111111';

    private const array TABLES = ['webhook_endpoint', 'webhook_delivery', 'webhook_delivery_attempt', 'event_log', 'audit_entry', 'attachment'];

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
        $this->event('22222222-2222-2222-2222-222222222222');
        $this->event('33333333-3333-3333-3333-333333333333');

        $this->migrateTo(self::DROP);

        $tables = $this->db->createSchemaManager()->introspectSchema();
        foreach (self::TABLES as $table) {
            self::assertFalse($tables->getTable($table)->hasColumn('tenant_id'), $table);
        }
        self::assertSame(
            ['22222222-2222-2222-2222-222222222222', '33333333-3333-3333-3333-333333333333'],
            $this->db->fetchFirstColumn('SELECT id FROM event_log ORDER BY id'),
        );
        $audit = $tables->getTable('audit_entry');
        self::assertSame(['entity_class', 'entity_id'], $audit->getIndex('idx_audit_entry_entity')->getColumns());
        self::assertSame(['root_class', 'root_id'], $audit->getIndex('idx_audit_entry_root')->getColumns());
        self::assertSame(['correlation_id'], $audit->getIndex('idx_audit_entry_correlation')->getColumns());
        self::assertSame(['owner_kind', 'owner_id'], $tables->getTable('attachment')->getIndex('idx_attachment_owner')->getColumns());
        self::assertSame(['active'], $tables->getTable('webhook_endpoint')->getIndex('idx_webhook_endpoint_active')->getColumns());
        self::assertFalse($tables->getTable('event_log')->hasIndex('idx_event_log_tenant_position'));
    }

    public function testEmptyTablesGoBackDownWithTheColumn(): void
    {
        $this->migrateTo(self::DROP);

        $this->migrateTo(self::BEFORE);

        $tables = $this->db->createSchemaManager()->introspectSchema();
        foreach (self::TABLES as $table) {
            self::assertTrue($tables->getTable($table)->getColumn('tenant_id')->getNotnull(), $table);
        }
        self::assertSame(['tenant_id', 'entity_class', 'entity_id'], $tables->getTable('audit_entry')->getIndex('idx_audit_entry_entity')->getColumns());
        self::assertSame(['tenant_id', 'active'], $tables->getTable('webhook_endpoint')->getIndex('idx_webhook_endpoint_tenant_active')->getColumns());
        // PostgreSQL reports the keyword position quoted.
        self::assertSame(['tenant_id', 'position'], array_map(static fn (string $column): string => trim($column, '"'), $tables->getTable('event_log')->getIndex('idx_event_log_tenant_position')->getColumns()));
    }

    public function testRowsStopTheWayBackDown(): void
    {
        $this->event('22222222-2222-2222-2222-222222222222');
        $this->migrateTo(self::DROP);

        try {
            $this->migrateTo(self::BEFORE);
            self::fail('Rows without a tenant must stop the way down.');
        } catch (AbortMigration $e) {
            self::assertStringContainsString('event_log holds rows', $e->getMessage());
        }

        self::assertSame(['22222222-2222-2222-2222-222222222222'], $this->db->fetchFirstColumn('SELECT id FROM event_log'));
    }

    private function event(string $id): void
    {
        $this->db->insert('event_log', [
            'id' => $id,
            'tenant_id' => self::TENANT,
            'name' => 'party.created',
            'version' => 1,
            'occurred_at' => '2026-11-18 10:00:00',
            'subject_type' => 'party',
            'subject_id' => 'A',
            'data' => '{}',
            'actor_kind' => 'system',
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
