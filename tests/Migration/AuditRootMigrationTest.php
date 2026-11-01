<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Identity\Domain\Membership;
use App\Party\Domain\ContactLink;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyBankAccount;
use App\Party\Domain\PartyEmail;
use App\Party\Infrastructure\Doctrine\VatIdType;
use App\Shared\Domain\Id;
use App\Tenancy\Infrastructure\Doctrine\TenantIdType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Type;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\TestCase;

/**
 * Adds the root to audit entries written before it existed: a scratch database
 * on SQLite, or a schema of its own in the test database on PostgreSQL.
 */
final class AuditRootMigrationTest extends TestCase
{
    private const string BEFORE = 'DoctrineMigrations\Version20261101000001';
    private const string TENANT = '11111111-1111-1111-1111-111111111111';
    private const string SCHEMA = 'audit_root_migration_test';
    private const string AT = '2026-10-31 12:00:00';

    private Connection $db;

    /** @var array<string, string> */
    private array $ids = [];

    protected function setUp(): void
    {
        foreach (['tenant_id' => TenantIdType::class, 'vat_id' => VatIdType::class] as $name => $class) {
            if (!Type::hasType($name)) {
                Type::addType($name, $class);
            }
        }
        $this->db = $this->connect();
        $this->migrateTo(self::BEFORE);
        $this->seed();
    }

    protected function tearDown(): void
    {
        if ($this->db->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->db->executeStatement('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
        }
        $this->db->close();
    }

    public function testEntriesOfChildrenGetTheirPartyAsRoot(): void
    {
        $this->migrateTo(null);

        self::assertSame([Party::class, $this->ids['company']], $this->root('address'));
        self::assertSame([Party::class, $this->ids['company']], $this->root('email'));
        self::assertSame([Party::class, $this->ids['company']], $this->root('account'));
        self::assertSame([Party::class, $this->ids['company']], $this->root('link'), 'a contact link belongs to its company');
    }

    public function testEntriesOfEntitiesWithoutARootOrOfDeletedChildrenKeepTheirOwnId(): void
    {
        $this->migrateTo(null);

        self::assertSame([Party::class, $this->ids['company']], $this->root('party'));
        self::assertSame([PartyEmail::class, $this->ids['deleted email']], $this->root('deleted email'));
        self::assertSame([Membership::class, $this->ids['membership']], $this->root('membership'));
    }

    public function testTheIndexAndColumnsExist(): void
    {
        $this->migrateTo(null);

        $indexes = array_map(static fn (Index $index): string => $index->getObjectName()->getIdentifier()->getValue(), $this->db->createSchemaManager()->introspectTableIndexesByUnquotedName('audit_entry'));
        self::assertContains('idx_audit_entry_root', array_map(strtolower(...), $indexes));
    }

    public function testItCanBeRolledBack(): void
    {
        $this->migrateTo(null);
        $this->migrateTo(self::BEFORE);

        $columns = array_keys($this->db->createSchemaManager()->introspectTableColumnsByUnquotedName('audit_entry'));
        self::assertNotContains('root_class', $columns);
        self::assertNotContains('root_id', $columns);
    }

    /**
     * @return array{string, string}
     */
    private function root(string $label): array
    {
        $row = $this->db->fetchAssociative('SELECT root_class, root_id FROM audit_entry WHERE correlation_id = ?', [$label]);
        self::assertIsArray($row, $label);

        self::assertIsString($row['root_class']);
        self::assertIsString($row['root_id']);

        return [$row['root_class'], $row['root_id']];
    }

    private function seed(): void
    {
        foreach (['company', 'person', 'address', 'email', 'account', 'link', 'deleted email', 'membership', 'user'] as $key) {
            $this->ids[$key] = Id::generate();
        }
        $this->db->insert('tenant', ['id' => self::TENANT, 'name' => 'Example', 'created_at' => self::AT]);
        $this->db->insert('app_user', ['id' => $this->ids['user'], 'email' => 'user@example.com', 'display_name' => 'User', 'locale' => 'en', 'password_hash' => 'x']);
        $this->party($this->ids['company'], 'Example Company s.r.o.', 'company');
        $this->party($this->ids['person'], 'Example Person', 'person');
        $this->tenantRow('party_address', ['id' => $this->ids['address'], 'party_id' => $this->ids['company'], 'type' => 'billing', 'label' => '', 'address_street' => 'Example Street 1', 'address_city' => 'Example City', 'address_postal_code' => '100 00', 'address_country_code' => 'CZ', 'billing_flag' => true]);
        $this->tenantRow('party_email', ['id' => $this->ids['email'], 'party_id' => $this->ids['company'], 'address' => 'general@example.com', 'purpose' => 'general']);
        $this->tenantRow('party_bank_account', ['id' => $this->ids['account'], 'party_id' => $this->ids['company'], 'iban' => 'DE89370400440532013000', 'label' => '', 'primary_flag' => true]);
        $this->tenantRow('contact_link', ['id' => $this->ids['link'], 'company_id' => $this->ids['company'], 'person_id' => $this->ids['person'], 'position' => 'Director', 'is_primary' => true]);

        $this->audit('party', Party::class, $this->ids['company']);
        $this->audit('address', PartyAddress::class, $this->ids['address']);
        $this->audit('email', PartyEmail::class, $this->ids['email']);
        $this->audit('account', PartyBankAccount::class, $this->ids['account']);
        $this->audit('link', ContactLink::class, $this->ids['link']);
        $this->audit('deleted email', PartyEmail::class, $this->ids['deleted email']);
        $this->audit('membership', Membership::class, $this->ids['membership']);
    }

    private function party(string $id, string $name, string $kind): void
    {
        $this->tenantRow('party', [
            'id' => $id, 'kind' => $kind, 'display_name' => $name, 'phone' => '', 'roles' => '[]', 'private_note' => '',
            'invoicing_due_days' => 14, 'invoicing_currency' => 'CZK', 'invoicing_language' => 'cs', 'invoicing_payment_method' => 'bank_transfer', 'invoicing_reverse_charge' => false,
        ]);
    }

    private function audit(string $label, string $class, string $id): void
    {
        $this->tenantRow('audit_entry', [
            'id' => Id::generate(), 'entity_class' => $class, 'entity_id' => $id, 'action' => 'created', 'changes' => '{}',
            'user_id' => null, 'occurred_at' => self::AT, 'correlation_id' => $label,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function tenantRow(string $table, array $row): void
    {
        $row = ['tenant_id' => self::TENANT] + $row;
        $quoted = [];
        $types = [];
        foreach ($row as $column => $value) {
            $name = $this->db->quoteSingleIdentifier($column);
            $quoted[$name] = $value;
            $types[$name] = match (true) {
                \is_bool($value) => ParameterType::BOOLEAN,
                \is_int($value) => ParameterType::INTEGER,
                null === $value => ParameterType::NULL,
                default => ParameterType::STRING,
            };
        }
        $this->db->insert($table, $quoted, $types);
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

    private function migrateTo(?string $version): void
    {
        $migrations = DependencyFactory::fromConnection(
            new ConfigurationArray(['migrations_paths' => ['DoctrineMigrations' => \dirname(__DIR__, 2).'/migrations']]),
            new ExistingConnection($this->db),
        );
        $migrations->getMetadataStorage()->ensureInitialized();
        $plan = $migrations->getMigrationPlanCalculator()->getPlanUntilVersion(
            null === $version ? $migrations->getVersionAliasResolver()->resolveVersionAlias('latest') : new Version($version),
        );
        $migrations->getMigrator()->migrate($plan, new MigratorConfiguration());
    }
}
