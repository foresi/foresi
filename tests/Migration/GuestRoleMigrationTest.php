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
 * Turns the client and subcontractor memberships into guest ones: a scratch
 * database on SQLite, or a schema of its own in the test database on PostgreSQL.
 */
final class GuestRoleMigrationTest extends TestCase
{
    private const string BEFORE = 'DoctrineMigrations\Version20261107090001';
    private const string AFTER = 'DoctrineMigrations\Version20261107100001';
    private const string TENANT = '11111111-1111-1111-1111-111111111111';
    private const string SCHEMA = 'guest_role_migration_test';

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
        $this->db->insert('tenant', ['id' => self::TENANT, 'name' => 'Example', 'created_at' => '2026-11-01 12:00:00']);
        foreach (['owner', 'member', 'client', 'subcontractor'] as $role) {
            $user = Id::generate();
            $this->db->insert('app_user', ['id' => $user, 'email' => $role.'@example.com', 'password_hash' => 'x', 'display_name' => $role, 'locale' => 'cs']);
            $this->db->insert('membership', ['id' => Id::generate(), 'tenant_id' => self::TENANT, 'user_id' => $user, 'role' => $role]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->db->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->db->executeStatement('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
        }
        $this->db->close();
    }

    public function testClientsAndSubcontractorsBecomeGuestsAndTheStaffStays(): void
    {
        $this->migrateTo(self::AFTER);

        self::assertSame(
            ['client@example.com' => 'guest', 'member@example.com' => 'member', 'owner@example.com' => 'owner', 'subcontractor@example.com' => 'guest'],
            $this->roles(),
        );
    }

    public function testGoingDownMakesEveryGuestMembershipAClientOne(): void
    {
        $this->migrateTo(self::AFTER);
        $this->migrateTo(self::BEFORE);

        self::assertSame(
            ['client@example.com' => 'client', 'member@example.com' => 'member', 'owner@example.com' => 'owner', 'subcontractor@example.com' => 'client'],
            $this->roles(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function roles(): array
    {
        $roles = [];
        foreach ($this->db->fetchAllNumeric('SELECT u.email, m.role FROM membership m JOIN app_user u ON u.id = m.user_id ORDER BY u.email') as [$email, $role]) {
            if (\is_string($email) && \is_string($role)) {
                $roles[$email] = $role;
            }
        }

        return $roles;
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
