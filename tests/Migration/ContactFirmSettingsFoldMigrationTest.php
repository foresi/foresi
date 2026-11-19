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
use Foresi\Party\Infrastructure\Doctrine\VatIdType;
use Foresi\Shared\Domain\Id;
use PHPUnit\Framework\TestCase;

/**
 * What the one firm had of its own for a contact becomes the contact's own
 * value, and its own invoicing e-mails become the contact's: a scratch database
 * on SQLite, or a schema of its own in the test database on PostgreSQL.
 */
final class ContactFirmSettingsFoldMigrationTest extends TestCase
{
    private const string BEFORE = 'DoctrineMigrations\Version20261119410001';
    private const string AFTER = 'DoctrineMigrations\Version20261119700001';
    private const string SCHEMA = 'contact_firm_settings_fold_migration_test';

    private Connection $db;
    private string $firm;

    protected function setUp(): void
    {
        foreach (['vat_id' => VatIdType::class] as $name => $class) {
            if (!Type::hasType($name)) {
                Type::addType($name, $class);
            }
        }
        $this->db = $this->connect();
        $this->migrateTo(self::BEFORE);
        $this->firm = $this->firm();
    }

    protected function tearDown(): void
    {
        if ($this->db->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->db->executeStatement('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
        }
        $this->db->close();
    }

    public function testTheFirmsRolesDefaultsAndArchiveBecomeTheContacts(): void
    {
        $party = $this->party();
        $this->settings($party, [
            'roles' => '["supplier"]',
            'invoicing_due_days' => 30,
            'invoicing_currency' => 'EUR',
            'invoicing_language' => 'en',
            'invoicing_payment_method' => 'card',
            'invoicing_reverse_charge' => 1,
            'invoicing_send_reminders' => null,
            'invoicing_hourly_rate_minor' => 150000,
            'invoicing_hourly_rate_currency' => 'EUR',
            'archived_at' => '2026-11-10 12:00:00',
        ]);

        $this->migrateTo(self::AFTER);

        $row = $this->db->fetchAssociative('SELECT * FROM party WHERE id = ?', [$party]);
        self::assertIsArray($row);
        self::assertSame(['supplier'], json_decode($this->text($row['roles']), true));
        self::assertEquals(30, $row['invoicing_due_days']);
        self::assertSame('EUR', $row['invoicing_currency']);
        self::assertSame('en', $row['invoicing_language']);
        self::assertSame('card', $row['invoicing_payment_method']);
        self::assertTrue((bool) $row['invoicing_reverse_charge']);
        self::assertTrue((bool) $row['invoicing_send_reminders'], 'A row from before the flag sent reminders.');
        self::assertEquals(150000, $row['invoicing_hourly_rate_minor']);
        self::assertSame('EUR', $row['invoicing_hourly_rate_currency']);
        self::assertStringStartsWith('2026-11-10 12:00:00', $this->text($row['archived_at']));
        self::assertFalse($this->db->createSchemaManager()->tablesExist(['party_firm_settings']));
    }

    public function testValuesTheFirmDidNotHaveStayTheContacts(): void
    {
        $party = $this->party(['archived_at' => '2026-11-01 12:00:00']);
        $this->settings($party, ['roles' => null, 'archived_at' => '2026-11-10 12:00:00']);
        $untouched = $this->party();

        $this->migrateTo(self::AFTER);

        $row = $this->db->fetchAssociative('SELECT roles, invoicing_due_days, invoicing_currency, invoicing_send_reminders, archived_at FROM party WHERE id = ?', [$party]);
        self::assertIsArray($row);
        self::assertSame(['customer'], json_decode($this->text($row['roles']), true));
        self::assertEquals(14, $row['invoicing_due_days']);
        self::assertSame('CZK', $row['invoicing_currency']);
        self::assertFalse((bool) $row['invoicing_send_reminders']);
        self::assertStringStartsWith('2026-11-01 12:00:00', $this->text($row['archived_at']), 'The earlier archive stays.');
        self::assertSame(['["customer"]', null], $this->db->fetchNumeric('SELECT roles, archived_at FROM party WHERE id = ?', [$untouched]));
    }

    public function testTheFirmsOwnInvoicingEmailsReplaceTheSharedOnes(): void
    {
        $party = $this->party();
        $this->email($party, 'hello@example.com', 'general', null);
        $this->email($party, 'shared@example.com', 'invoices', null);
        $this->email($party, 'firm@example.com', 'invoices', $this->firm);
        $this->email($party, 'copy@example.com', 'copy', $this->firm);
        $other = $this->party();
        $this->email($other, 'other@example.com', 'invoices', null);

        $this->migrateTo(self::AFTER);

        self::assertSame(['copy@example.com', 'firm@example.com', 'hello@example.com'], $this->db->fetchFirstColumn('SELECT address FROM party_email WHERE party_id = ? ORDER BY address', [$party]));
        self::assertSame(['other@example.com'], $this->db->fetchFirstColumn('SELECT address FROM party_email WHERE party_id = ?', [$other]));
        $columns = array_keys($this->db->createSchemaManager()->introspectTableColumnsByUnquotedName('party_email'));
        self::assertNotContains('legal_entity_id', $columns);
    }

    public function testItCanBeRolledBack(): void
    {
        $party = $this->party();
        $this->settings($party, ['roles' => '["supplier"]']);
        $this->email($party, 'firm@example.com', 'invoices', $this->firm);
        $this->migrateTo(self::AFTER);

        $this->migrateTo(self::BEFORE);

        self::assertFalse($this->db->fetchOne('SELECT 1 FROM party_firm_settings'));
        self::assertSame([null], $this->db->fetchFirstColumn('SELECT legal_entity_id FROM party_email'));
        self::assertSame('["supplier"]', $this->db->fetchOne('SELECT roles FROM party WHERE id = ?', [$party]));
    }

    private function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * @param array<string, string|null> $extra
     */
    private function party(array $extra = []): string
    {
        $id = Id::generate();
        $this->db->insert('party', $extra + [
            'id' => $id,
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
            'invoicing_send_reminders' => 0,
        ]);

        return $id;
    }

    /**
     * @param array<string, int|string|null> $values
     */
    private function settings(string $party, array $values): void
    {
        $this->db->insert('party_firm_settings', $values + ['id' => Id::generate(), 'party_id' => $party, 'legal_entity_id' => $this->firm]);
    }

    private function email(string $party, string $address, string $purpose, ?string $firm): void
    {
        $this->db->insert('party_email', ['id' => Id::generate(), 'party_id' => $party, 'address' => $address, 'address_key' => $address, 'purpose' => $purpose, 'legal_entity_id' => $firm]);
    }

    private function firm(): string
    {
        $id = Id::generate();
        $this->db->insert('legal_entity', [
            'id' => $id,
            'name' => 'Example Firm',
            'vat_status' => 'not_registered',
            'register_entry' => '',
            'registration_country' => 'CZ',
            'registration_number' => '12345679',
            'active_registration_key' => '12345679',
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
