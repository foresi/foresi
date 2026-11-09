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
 * The work that exists before sharing stays open to the organisation and
 * nothing of it turns private: a scratch database on SQLite, or a schema of
 * its own in the test database on PostgreSQL.
 */
final class SharingMigrationTest extends TestCase
{
    private const string BEFORE = 'DoctrineMigrations\Version20261108140001';
    private const string AFTER = 'DoctrineMigrations\Version20261109120001';
    private const string TENANT = '11111111-1111-1111-1111-111111111111';
    private const string SCHEMA = 'sharing_migration_test';

    private Connection $db;
    private string $project;
    private string $section;
    private string $task;
    private string $membership;

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
        $user = Id::generate();
        $this->db->insert('app_user', ['id' => $user, 'email' => 'member@example.com', 'password_hash' => 'x', 'display_name' => 'Member', 'locale' => 'cs']);
        $this->membership = Id::generate();
        $this->db->insert('membership', ['id' => $this->membership, 'tenant_id' => self::TENANT, 'user_id' => $user, 'role' => 'member']);
        $this->project = Id::generate();
        $this->db->insert('project', ['id' => $this->project, 'tenant_id' => self::TENANT, 'name' => 'Website', 'description' => '', 'workflow_template' => 'simple', 'created_by' => $user, 'created_at' => '2026-11-01 12:00:00']);
        $status = Id::generate();
        $this->db->insert('project_status', ['id' => $status, 'tenant_id' => self::TENANT, 'project_id' => $this->project, 'name' => 'To do', 'type' => 'open', 'position' => 0]);
        $this->section = Id::generate();
        $this->db->insert('project_section', ['id' => $this->section, 'tenant_id' => self::TENANT, 'project_id' => $this->project, 'name' => 'Design', 'position' => 0]);
        $this->task = Id::generate();
        $this->db->insert('task', ['id' => $this->task, 'tenant_id' => self::TENANT, 'project_id' => $this->project, 'section_id' => $this->section, 'title' => 'Write the brief', 'description' => '', 'status_id' => $status, 'priority' => 'none', 'position' => 0, 'created_by' => $user, 'created_at' => '2026-11-01 12:00:00']);
    }

    protected function tearDown(): void
    {
        if ($this->db->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->db->executeStatement('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
        }
        $this->db->close();
    }

    public function testExistingWorkStaysOpenToTheOrganisationAndNothingIsPrivate(): void
    {
        $this->migrateTo(self::AFTER);

        self::assertSame('organisation', $this->db->fetchOne('SELECT visibility FROM project WHERE id = ?', [$this->project]));
        self::assertFalse((bool) $this->db->fetchOne('SELECT private FROM project_section WHERE id = ?', [$this->section]));
        self::assertFalse((bool) $this->db->fetchOne('SELECT private FROM task WHERE id = ?', [$this->task]));
        self::assertEquals(0, $this->db->fetchOne('SELECT COUNT(*) FROM work_share'));
    }

    public function testAPersonIsSharedAnItemOnceAndEachKindHasItsOwnRow(): void
    {
        $this->migrateTo(self::AFTER);
        foreach (['project_id' => $this->project, 'section_id' => $this->section, 'task_id' => $this->task] as $column => $id) {
            $this->share($column, $id);
        }
        self::assertEquals(3, $this->db->fetchOne('SELECT COUNT(*) FROM work_share'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->share('task_id', $this->task);
    }

    public function testGoingDownRemovesSharing(): void
    {
        $this->migrateTo(self::AFTER);
        $this->migrateTo(self::BEFORE);

        $tables = $this->db->createSchemaManager()->listTableNames();
        self::assertNotContains('work_share', $tables);
        self::assertArrayNotHasKey('visibility', $this->db->createSchemaManager()->listTableColumns('project'));
        self::assertArrayNotHasKey('private', $this->db->createSchemaManager()->listTableColumns('task'));
        self::assertSame('Write the brief', $this->db->fetchOne('SELECT title FROM task WHERE id = ?', [$this->task]));
    }

    private function share(string $column, string $id): void
    {
        $this->db->insert('work_share', ['id' => Id::generate(), 'tenant_id' => self::TENANT, 'membership_id' => $this->membership, $column => $id, 'created_at' => '2026-11-09 12:00:00']);
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
