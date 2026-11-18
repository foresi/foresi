<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
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
 * The tenant column leaves the party, identity and import tables and the API
 * idempotency keys with their rows kept, and the indexes that led with it hold
 * on their remaining columns. A scratch database on SQLite, or a schema of its
 * own in the test database on PostgreSQL.
 */
final class PartyIdentityImportTenantColumnMigrationTest extends TestCase
{
    private const string BEFORE = 'DoctrineMigrations\Version20261118000002';
    private const string DROP = 'DoctrineMigrations\Version20261118000006';
    private const string SCHEMA = 'party_identity_import_tenant_column_migration_test';
    private const string TENANT = '11111111-1111-1111-1111-111111111111';
    private const string USER = '44444444-4444-4444-4444-444444444444';

    private const array TABLES = [
        'party', 'party_address', 'party_bank_account', 'party_email', 'party_firm_settings', 'contact_link', 'firm_bank_account', 'legal_entity',
        'membership', 'invitation', 'api_token', 'api_idempotency_key',
        'import', 'import_row', 'import_profile', 'import_ambiguous_match', 'import_missing_record',
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
        $this->party('22222222-2222-2222-2222-222222222222');
        $this->party('33333333-3333-3333-3333-333333333333');
        $this->member();
        $this->idempotencyKey();

        $this->migrateTo(self::DROP);

        $tables = $this->db->createSchemaManager()->introspectSchema();
        foreach (self::TABLES as $table) {
            self::assertFalse($tables->getTable($table)->hasColumn('tenant_id'), $table);
        }
        self::assertSame(
            ['22222222-2222-2222-2222-222222222222', '33333333-3333-3333-3333-333333333333'],
            $this->db->fetchFirstColumn('SELECT id FROM party ORDER BY id'),
        );
        self::assertSame([self::USER], $this->db->fetchFirstColumn('SELECT user_id FROM membership'));
        self::assertSame(['create-1'], $this->db->fetchFirstColumn('SELECT idempotency_key FROM api_idempotency_key'));

        $membership = $tables->getTable('membership');
        self::assertTrue($membership->getIndex('uniq_membership_user')->isUnique());
        self::assertSame(['user_id'], $membership->getIndex('uniq_membership_user')->getColumns());
        self::assertFalse($membership->hasIndex('uniq_membership_tenant_user'));
        self::assertSame(['company_id', 'person_id'], $tables->getTable('contact_link')->getIndex('uniq_contact_link_pair')->getColumns());
        self::assertSame(['party_id', 'legal_entity_id'], $tables->getTable('party_firm_settings')->getIndex('uniq_party_firm_settings')->getColumns());
        self::assertSame(['registration_country', 'active_registration_key'], $tables->getTable('legal_entity')->getIndex('uniq_legal_entity_active_registration')->getColumns());
        self::assertSame(['registration_key'], $tables->getTable('party')->getIndex('idx_party_registration_key')->getColumns());
        self::assertSame(['target_key', 'header_signature'], $tables->getTable('import_profile')->getIndex('idx_import_profile_signature')->getColumns());
        self::assertSame(['token_id', 'operation', 'idempotency_key'], $tables->getTable('api_idempotency_key')->getPrimaryKey()?->getColumns());
    }

    public function testOnePersonHasOneMembership(): void
    {
        $this->member();
        $this->migrateTo(self::DROP);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->db->insert('membership', ['id' => '55555555-5555-5555-5555-555555555555', 'user_id' => self::USER, 'role' => 'member']);
    }

    public function testEmptyTablesGoBackDownWithTheColumn(): void
    {
        $this->migrateTo(self::DROP);

        $this->migrateTo(self::BEFORE);

        $tables = $this->db->createSchemaManager()->introspectSchema();
        foreach (self::TABLES as $table) {
            self::assertTrue($tables->getTable($table)->getColumn('tenant_id')->getNotnull(), $table);
        }
        self::assertSame(['tenant_id', 'user_id'], $tables->getTable('membership')->getIndex('uniq_membership_tenant_user')->getColumns());
        self::assertSame(['tenant_id', 'company_id', 'person_id'], $tables->getTable('contact_link')->getIndex('uniq_contact_link_pair')->getColumns());
        self::assertSame(['tenant_id', 'token_id', 'operation', 'idempotency_key'], $tables->getTable('api_idempotency_key')->getPrimaryKey()?->getColumns());
    }

    public function testRowsStopTheWayBackDown(): void
    {
        $this->party('22222222-2222-2222-2222-222222222222');
        $this->migrateTo(self::DROP);

        try {
            $this->migrateTo(self::BEFORE);
            self::fail('Rows without a tenant must stop the way down.');
        } catch (AbortMigration $e) {
            self::assertStringContainsString('party holds rows', $e->getMessage());
        }

        self::assertSame(['22222222-2222-2222-2222-222222222222'], $this->db->fetchFirstColumn('SELECT id FROM party'));
    }

    private function party(string $id): void
    {
        $this->db->insert('party', [
            'id' => $id,
            'tenant_id' => self::TENANT,
            'kind' => 'company',
            'display_name' => 'Example Ltd',
            'phone' => '',
            'roles' => '[]',
            'private_note' => '',
            'invoicing_due_days' => 14,
            'invoicing_currency' => 'CZK',
            'invoicing_language' => 'cs',
            'invoicing_payment_method' => 'bank_transfer',
            'mobile' => '',
            'website' => '',
            'invoicing_send_reminders' => true,
            'invoicing_reverse_charge' => false,
        ], ['invoicing_reverse_charge' => ParameterType::BOOLEAN, 'invoicing_send_reminders' => ParameterType::BOOLEAN]);
    }

    private function member(): void
    {
        $this->db->insert('app_user', ['id' => self::USER, 'email' => 'anna@example.com', 'display_name' => 'Anna', 'locale' => 'en', 'password_hash' => 'x']);
        $this->db->insert('membership', ['id' => '66666666-6666-6666-6666-666666666666', 'tenant_id' => self::TENANT, 'user_id' => self::USER, 'role' => 'owner']);
    }

    private function idempotencyKey(): void
    {
        $this->db->insert('api_idempotency_key', [
            'tenant_id' => self::TENANT,
            'token_id' => '77777777-7777-7777-7777-777777777777',
            'operation' => 'POST /api/v1/contacts',
            'idempotency_key' => 'create-1',
            'fingerprint' => str_repeat('a', 64),
            'status' => 'completed',
            'created_at' => '2026-11-18 10:00:00',
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
