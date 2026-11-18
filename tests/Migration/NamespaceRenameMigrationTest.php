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
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyEmail;
use Foresi\Party\Infrastructure\Doctrine\VatIdType;
use Foresi\Shared\Domain\Id;
use PHPUnit\Framework\TestCase;

/**
 * The class names in the audit log move from App\ to Foresi\ and back. A
 * scratch database on SQLite, or a schema of its own in the test database on
 * PostgreSQL.
 */
final class NamespaceRenameMigrationTest extends TestCase
{
    private const string BEFORE = 'DoctrineMigrations\Version20261118000007';
    private const string RENAME = 'DoctrineMigrations\Version20261118100001';
    private const string SCHEMA = 'namespace_rename_migration_test';

    private Connection $db;

    protected function setUp(): void
    {
        if (!Type::hasType('vat_id')) {
            Type::addType('vat_id', VatIdType::class);
        }
        $this->db = $this->connect();
        $this->migrateTo(self::BEFORE);
        $this->audit('child', 'App\Party\Domain\PartyEmail', 'App\Party\Domain\Party');
        $this->audit('other', 'Vendor\Module\Thing', 'Vendor\Module\Thing');
        $this->audit('lookalike', 'Application\Thing', 'AppX\Thing');
    }

    protected function tearDown(): void
    {
        if ($this->db->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->db->executeStatement('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
        }
        $this->db->close();
    }

    public function testTheStoredClassNamesMoveToTheNewNamespace(): void
    {
        $this->migrateTo(self::RENAME);

        self::assertSame([PartyEmail::class, Party::class], $this->classes('child'));
        self::assertSame(['Vendor\Module\Thing', 'Vendor\Module\Thing'], $this->classes('other'));
        self::assertSame(['Application\Thing', 'AppX\Thing'], $this->classes('lookalike'));
    }

    public function testItCanBeRolledBack(): void
    {
        $this->migrateTo(self::RENAME);
        $this->migrateTo(self::BEFORE);

        self::assertSame(['App\Party\Domain\PartyEmail', 'App\Party\Domain\Party'], $this->classes('child'));
        self::assertSame(['Vendor\Module\Thing', 'Vendor\Module\Thing'], $this->classes('other'));
    }

    private function audit(string $label, string $class, string $rootClass): void
    {
        $this->db->insert('audit_entry', [
            'id' => Id::generate(), 'entity_class' => $class, 'entity_id' => 'e-'.$label, 'root_class' => $rootClass, 'root_id' => 'r-'.$label,
            'action' => 'created', 'changes' => '{}', 'user_id' => null, 'occurred_at' => '2026-11-18 12:00:00', 'correlation_id' => $label,
        ]);
    }

    /**
     * @return array{mixed, mixed}
     */
    private function classes(string $label): array
    {
        $row = $this->db->fetchAssociative('SELECT entity_class, root_class FROM audit_entry WHERE correlation_id = ?', [$label]);
        self::assertIsArray($row, $label);

        return [$row['entity_class'], $row['root_class']];
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
