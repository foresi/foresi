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
 * Stored firm settings stay what they were, as invoicing overrides, and every
 * existing e-mail stays shared: a scratch database on SQLite, or a schema of
 * its own in the test database on PostgreSQL.
 */
final class FirmScopeMigrationTest extends TestCase
{
    private const string BEFORE = 'DoctrineMigrations\Version20261101000004';
    private const string AFTER = 'DoctrineMigrations\Version20261101000005';
    private const string TENANT = '11111111-1111-1111-1111-111111111111';
    private const string SCHEMA = 'firm_scope_migration_test';

    private Connection $db;
    private string $party;
    private string $firm;

    protected function setUp(): void
    {
        foreach (['tenant_id' => TenantIdType::class, 'vat_id' => VatIdType::class] as $name => $class) {
            if (!Type::hasType($name)) {
                Type::addType($name, $class);
            }
        }
        $this->db = $this->connect();
        $this->migrateTo(self::BEFORE);
        $this->db->insert('tenant', ['id' => self::TENANT, 'name' => 'Example', 'created_at' => '2026-10-31 12:00:00']);
        $this->party = $this->party();
        $this->firm = $this->firm();
    }

    protected function tearDown(): void
    {
        if ($this->db->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->db->executeStatement('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
        }
        $this->db->close();
    }

    public function testTaskSixRowsSurviveTheMigration(): void
    {
        $this->settings();
        $this->email();

        $this->migrateTo(self::AFTER);

        $row = $this->db->fetchAssociative('SELECT * FROM party_firm_settings');
        self::assertIsArray($row);
        self::assertSame($this->party, $row['party_id']);
        self::assertSame($this->firm, $row['legal_entity_id']);
        self::assertEquals(30, $row['invoicing_due_days']);
        self::assertSame('EUR', $row['invoicing_currency']);
        self::assertSame('en', $row['invoicing_language']);
        self::assertSame('card', $row['invoicing_payment_method']);
        self::assertTrue((bool) $row['invoicing_reverse_charge']);
        self::assertNull($row['roles']);
        self::assertNull($row['archived_at']);
        self::assertSame([['invoices@example.com', null]], $this->db->fetchAllNumeric('SELECT address, legal_entity_id FROM party_email'));
    }

    public function testItAllowsARowWithoutInvoicingValues(): void
    {
        $this->migrateTo(self::AFTER);

        $this->db->insert('party_firm_settings', ['id' => Id::generate(), 'tenant_id' => self::TENANT, 'party_id' => $this->party, 'legal_entity_id' => $this->firm, 'roles' => '["supplier"]']);

        self::assertNull($this->db->fetchOne('SELECT invoicing_due_days FROM party_firm_settings'));
    }

    public function testItCanBeRolledBack(): void
    {
        $this->settings();
        $this->migrateTo(self::AFTER);
        $this->db->insert('party_firm_settings', ['id' => Id::generate(), 'tenant_id' => self::TENANT, 'party_id' => $this->firstOtherParty(), 'legal_entity_id' => $this->firm, 'roles' => '["supplier"]']);
        $this->db->insert('party_email', ['id' => Id::generate(), 'tenant_id' => self::TENANT, 'party_id' => $this->party, 'address' => 'firm@example.com', 'address_key' => 'firm@example.com', 'purpose' => 'invoices', 'legal_entity_id' => $this->firm]);

        $this->migrateTo(self::BEFORE);

        self::assertEquals([30], $this->db->fetchFirstColumn('SELECT invoicing_due_days FROM party_firm_settings'), 'A row with no invoicing values has no place in the old table.');
        self::assertEquals(0, $this->db->fetchOne('SELECT COUNT(*) FROM party_email'), 'Firm e-mails have no place in the old table.');
        $columns = array_keys($this->db->createSchemaManager()->introspectTableColumnsByUnquotedName('party_email'));
        self::assertNotContains('legal_entity_id', $columns);
    }

    private function settings(): void
    {
        $this->db->insert('party_firm_settings', [
            'id' => Id::generate(),
            'tenant_id' => self::TENANT,
            'party_id' => $this->party,
            'legal_entity_id' => $this->firm,
            'invoicing_due_days' => 30,
            'invoicing_currency' => 'EUR',
            'invoicing_language' => 'en',
            'invoicing_payment_method' => 'card',
            'invoicing_reverse_charge' => 1,
        ]);
    }

    private function email(): void
    {
        $this->db->insert('party_email', ['id' => Id::generate(), 'tenant_id' => self::TENANT, 'party_id' => $this->party, 'address' => 'invoices@example.com', 'address_key' => 'invoices@example.com', 'purpose' => 'invoices']);
    }

    private function firstOtherParty(): string
    {
        return $this->party();
    }

    private function party(): string
    {
        $id = Id::generate();
        $this->db->insert('party', [
            'id' => $id,
            'tenant_id' => self::TENANT,
            'display_name' => 'Example Company',
            'kind' => 'company',
            'phone' => '',
            'roles' => '["customer"]',
            'private_note' => '',
            'invoicing_due_days' => 14,
            'invoicing_currency' => 'CZK',
            'invoicing_language' => 'cs',
            'invoicing_payment_method' => 'bank_transfer',
            'invoicing_reverse_charge' => 0,
        ]);

        return $id;
    }

    private function firm(): string
    {
        $id = Id::generate();
        $this->db->insert('legal_entity', [
            'id' => $id,
            'tenant_id' => self::TENANT,
            'name' => 'Example Firm',
            'vat_status' => 'not_registered',
            'register_entry' => '',
            'bank_accounts' => '[]',
            'registration_country' => 'CZ',
            'registration_number' => '12345695',
            'active_registration_key' => '12345695',
            'address_street' => 'Example Street 1',
            'address_city' => 'Example City',
            'address_postal_code' => '100 00',
            'address_country_code' => 'CZ',
        ]);

        return $id;
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
