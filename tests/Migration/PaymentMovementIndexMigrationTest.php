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
 * A bank movement pays through one payment that is not cancelled: a scratch
 * database on SQLite, or a schema of its own in the test database on PostgreSQL.
 */
final class PaymentMovementIndexMigrationTest extends TestCase
{
    private const string BEFORE = 'DoctrineMigrations\Version20261103030001';
    private const string AFTER = 'DoctrineMigrations\Version20261103030002';
    private const string TENANT = '11111111-1111-1111-1111-111111111111';
    private const string MOVEMENT = '01963c5e-0000-7000-8000-0000000000aa';
    private const string SCHEMA = 'payment_movement_index_migration_test';

    private Connection $db;
    private string $firm;

    protected function setUp(): void
    {
        foreach (['tenant_id' => TenantIdType::class, 'vat_id' => VatIdType::class] as $name => $class) {
            if (!Type::hasType($name)) {
                Type::addType($name, $class);
            }
        }
        $this->db = $this->connect();
        $this->migrateTo(self::AFTER);
        $this->db->insert('tenant', ['id' => self::TENANT, 'name' => 'Example', 'created_at' => '2026-10-31 12:00:00']);
        $this->firm = Id::generate();
        $this->db->insert('legal_entity', [
            'id' => $this->firm,
            'tenant_id' => self::TENANT,
            'name' => 'Example Firm s.r.o.',
            'vat_status' => 'not_registered',
            'register_entry' => '',
            'registration_country' => 'CZ',
            'registration_number' => '12345695',
            'active_registration_key' => '12345695',
            'address_street' => 'Example Street 1',
            'address_city' => 'Example City',
            'address_postal_code' => '100 00',
            'address_country_code' => 'CZ',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->db->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->db->executeStatement('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
        }
        $this->db->close();
    }

    public function testASecondActivePaymentOfOneMovementIsRefused(): void
    {
        $this->payment(self::MOVEMENT);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->payment(self::MOVEMENT);
    }

    public function testACancelledPaymentFreesItsMovement(): void
    {
        $this->payment(self::MOVEMENT, cancelledAt: '2026-11-03 10:00:00');
        $this->payment(self::MOVEMENT, cancelledAt: '2026-11-03 11:00:00');
        $this->payment(self::MOVEMENT);
        // Payments without a movement are many.
        $this->payment(null);
        $this->payment(null);

        self::assertEquals(5, $this->db->fetchOne('SELECT COUNT(*) FROM payment'));
    }

    public function testDownDropsTheIndex(): void
    {
        $this->migrateTo(self::BEFORE);

        $this->payment(self::MOVEMENT);
        $this->payment(self::MOVEMENT);
        self::assertEquals(2, $this->db->fetchOne('SELECT COUNT(*) FROM payment'));
    }

    private function payment(?string $movement, ?string $cancelledAt = null): void
    {
        $this->db->insert('payment', [
            'id' => Id::generate(),
            'tenant_id' => self::TENANT,
            'legal_entity_id' => $this->firm,
            'received_on' => '2026-11-03',
            'amount_minor' => 121000,
            'currency' => 'CZK',
            'method' => 'bank_transfer',
            'source' => null === $movement ? 'manual' : 'bank_movement',
            'bank_movement_id' => $movement,
            'note' => '',
            'created_at' => '2026-11-03 09:00:00',
            'cancelled_at' => $cancelledAt,
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
