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
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\TestCase;

/**
 * A project for a client bills time by default and an internal one does not,
 * and a person runs one timer at a time: a scratch database on SQLite, or a
 * schema of its own in the test database on PostgreSQL.
 */
final class TimeMigrationTest extends TestCase
{
    private const string BEFORE = 'DoctrineMigrations\Version20261109150001';
    private const string AFTER = 'DoctrineMigrations\Version20261109160001';
    private const string TENANT = '11111111-1111-1111-1111-111111111111';
    private const string SCHEMA = 'time_migration_test';

    private Connection $db;
    private string $internal;
    private string $forClient;
    private string $membership;
    private string $user;

    protected function setUp(): void
    {
        foreach (['tenant_id' => TenantIdType::class, 'vat_id' => VatIdType::class] as $name => $class) {
            if (!Type::hasType($name)) {
                Type::addType($name, $class);
            }
        }
        $this->db = $this->connect();
        $this->migrateTo(self::BEFORE);
        $this->db->insert('tenant', ['id' => self::TENANT, 'name' => 'Example', 'created_at' => '2026-11-01 12:00:00']);
        $this->user = Id::generate();
        $this->db->insert('app_user', ['id' => $this->user, 'email' => 'member@example.com', 'password_hash' => 'x', 'display_name' => 'Member', 'locale' => 'cs']);
        $this->membership = Id::generate();
        $this->db->insert('membership', ['id' => $this->membership, 'tenant_id' => self::TENANT, 'user_id' => $this->user, 'role' => 'member']);
        $client = Id::generate();
        $this->db->insert('party', [
            'id' => $client,
            'tenant_id' => self::TENANT,
            'display_name' => 'Example Client',
            'kind' => 'person',
            'phone' => '',
            'roles' => '["customer"]',
            'private_note' => '',
            'invoicing_due_days' => 14,
            'invoicing_currency' => 'CZK',
            'invoicing_language' => 'cs',
            'invoicing_payment_method' => 'bank_transfer',
            'invoicing_reverse_charge' => 0,
        ]);
        $this->internal = $this->project(null);
        $this->forClient = $this->project($client);
    }

    protected function tearDown(): void
    {
        if ($this->db->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->db->executeStatement('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
        }
        $this->db->close();
    }

    public function testAnExistingProjectBillsTimeByDefaultWhenItHasAClient(): void
    {
        $this->migrateTo(self::AFTER);

        self::assertFalse((bool) $this->db->fetchOne('SELECT billable_by_default FROM project WHERE id = ?', [$this->internal]));
        self::assertTrue((bool) $this->db->fetchOne('SELECT billable_by_default FROM project WHERE id = ?', [$this->forClient]));
        self::assertNull($this->db->fetchOne('SELECT estimate_minutes FROM task'));
    }

    public function testAPersonRunsOneTimerAndStoppedEntriesDoNotCount(): void
    {
        $this->migrateTo(self::AFTER);
        $this->entry(null, 30);
        $this->entry(null, 45);
        $this->entry($this->membership, null);
        self::assertEquals(3, $this->db->fetchOne('SELECT COUNT(*) FROM time_entry'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entry($this->membership, null);
    }

    public function testGoingDownRemovesTime(): void
    {
        $this->migrateTo(self::AFTER);
        $this->migrateTo(self::BEFORE);

        self::assertNotContains('time_entry', $this->db->createSchemaManager()->listTableNames());
        self::assertArrayNotHasKey('billable_by_default', $this->db->createSchemaManager()->listTableColumns('project'));
        self::assertArrayNotHasKey('estimate_minutes', $this->db->createSchemaManager()->listTableColumns('task'));
    }

    private function project(?string $client): string
    {
        $id = Id::generate();
        $this->db->insert('project', ['id' => $id, 'tenant_id' => self::TENANT, 'client_id' => $client, 'name' => 'Website', 'description' => '', 'workflow_template' => 'simple', 'created_by' => $this->user, 'created_at' => '2026-11-01 12:00:00']);
        if (null === $client) {
            $status = Id::generate();
            $this->db->insert('project_status', ['id' => $status, 'tenant_id' => self::TENANT, 'project_id' => $id, 'name' => 'To do', 'type' => 'open', 'position' => 0]);
            $this->db->insert('task', ['id' => Id::generate(), 'tenant_id' => self::TENANT, 'project_id' => $id, 'title' => 'Write the brief', 'description' => '', 'status_id' => $status, 'priority' => 'none', 'position' => 0, 'created_by' => $this->user, 'created_at' => '2026-11-01 12:00:00']);
        }

        return $id;
    }

    private function entry(?string $runningFor, ?int $minutes): void
    {
        $this->db->insert('time_entry', [
            'id' => Id::generate(),
            'tenant_id' => self::TENANT,
            'project_id' => $this->internal,
            'membership_id' => $this->membership,
            'worked_on' => '2026-11-09',
            'minutes' => $minutes,
            'started_at' => null === $minutes ? '2026-11-09 09:00:00' : null,
            'note' => '',
            'billable' => false,
            'running_for' => $runningFor,
            'created_by' => $this->user,
            'created_at' => '2026-11-09 09:00:00',
        ], ['billable' => Types::BOOLEAN]);
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
