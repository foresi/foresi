<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Infrastructure;

use App\Party\Infrastructure\Doctrine\VatIdType;
use App\Tenancy\Infrastructure\Doctrine\TenantIdType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\TestCase;

/**
 * Runs the address book migrations on a scratch SQLite database that already
 * holds parties in the old shape (the booleans and the embedded address).
 */
final class AddressBookMigrationTest extends TestCase
{
    private const string BEFORE = 'DoctrineMigrations\Version20261029000004';
    private const string TENANT = '11111111-1111-1111-1111-111111111111';

    private Connection $connection;

    protected function setUp(): void
    {
        foreach (['tenant_id' => TenantIdType::class, 'vat_id' => VatIdType::class] as $name => $class) {
            if (!Type::hasType($name)) {
                Type::addType($name, $class);
            }
        }
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->migrateTo(self::BEFORE);
    }

    public function testBooleansBecomeRolesAndTheAddressBecomesTheBillingAddress(): void
    {
        $this->legacyParty('Customer', true, false, 'Customer Street 1');
        $this->legacyParty('Supplier', false, true, 'Supplier Street 2');
        $this->legacyParty('Both', true, true, 'Both Street 3');
        $this->legacyParty('Contact', false, false, 'Contact Street 4');

        $this->migrateTo(null);

        self::assertSame(
            ['Customer' => '["customer"]', 'Supplier' => '["supplier"]', 'Both' => '["customer","supplier"]', 'Contact' => '[]'],
            $this->connection->fetchAllKeyValue('SELECT display_name, roles FROM party'),
        );
        self::assertSame(
            ['Customer' => 'Customer Street 1', 'Supplier' => 'Supplier Street 2', 'Both' => 'Both Street 3', 'Contact' => 'Contact Street 4'],
            $this->connection->fetchAllKeyValue("SELECT p.display_name, a.address_street FROM party_address a JOIN party p ON p.id = a.party_id WHERE a.type = 'billing' AND a.billing_flag = 1"),
        );
        self::assertSame(4, $this->connection->fetchOne('SELECT COUNT(*) FROM party_address'));
        self::assertSame(self::TENANT, $this->connection->fetchOne('SELECT a.tenant_id FROM party_address a LIMIT 1'));
    }

    public function testEmailsKeysAndPrimaryFlagsAreMoved(): void
    {
        $this->legacyParty('With email', true, false, 'Street 1', 'invoices@example.com', 'CZ', '1234 5687');
        $this->legacyParty('Without email', true, false, 'Street 2');
        $partyId = $this->connection->fetchOne("SELECT id FROM party WHERE display_name = 'With email'");
        $this->migrateTo('DoctrineMigrations\\Version20261029000007');
        $this->connection->insert('party_bank_account', ['tenant_id' => self::TENANT, 'party_id' => $partyId, 'iban' => 'DE89370400440532013000', 'label' => '', 'is_primary' => 1]);
        $this->connection->insert('party_bank_account', ['tenant_id' => self::TENANT, 'party_id' => $partyId, 'iban' => 'GB82WEST12345698765432', 'label' => '', 'is_primary' => 0]);

        $this->migrateTo(null);

        self::assertSame(['With email' => '12345687'], $this->connection->fetchAllKeyValue('SELECT display_name, registration_key FROM party WHERE registration_key IS NOT NULL'));
        self::assertSame([['invoices@example.com', 'general']], $this->connection->fetchAllNumeric('SELECT address, purpose FROM party_email'));
        self::assertSame(['DE89370400440532013000' => 1, 'GB82WEST12345698765432' => null], $this->connection->fetchAllKeyValue('SELECT iban, primary_flag FROM party_bank_account'));
        $row = $this->connection->fetchAssociative('SELECT * FROM party');
        self::assertIsArray($row);
        self::assertArrayNotHasKey('email', $row);

        $this->migrateTo(self::BEFORE);

        self::assertSame(['With email' => 'invoices@example.com', 'Without email' => ''], $this->connection->fetchAllKeyValue('SELECT display_name, email FROM party'));
    }

    public function testAwkwardLegacyDataIsCleanedWhileMoving(): void
    {
        $this->legacyParty('Padded', true, false, 'Street 1', '  invoices@example.com  ', 'DE', 'straße ß');
        $this->legacyParty('Blank', true, false, 'Street 2', '   ', 'CZ', ' - / ');
        $this->legacyParty('Long', true, false, 'Street 3', str_repeat('a', 250).'@example.com');
        $padded = $this->connection->fetchOne("SELECT id FROM party WHERE display_name = 'Padded'");
        $this->migrateTo('DoctrineMigrations\\Version20261029000007');
        foreach ([['DE89370400440532013000', 1], ['GB82WEST12345698765432', 1], ['FR1420041010050500013M02606', 0]] as [$iban, $primary]) {
            $this->connection->insert('party_bank_account', ['tenant_id' => self::TENANT, 'party_id' => $padded, 'iban' => $iban, 'label' => '', 'is_primary' => $primary]);
        }

        $this->migrateTo(null);

        self::assertSame([['invoices@example.com']], $this->connection->fetchAllNumeric('SELECT address FROM party_email'));
        self::assertSame('STRASSESS', $this->connection->fetchOne("SELECT registration_key FROM party WHERE display_name = 'Padded'"));
        self::assertSame([['Blank', null, null]], $this->connection->fetchAllNumeric("SELECT display_name, registration_country, registration_number FROM party WHERE display_name = 'Blank'"));
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM party_bank_account WHERE primary_flag IS NOT NULL'));
        self::assertSame('DE89370400440532013000', $this->connection->fetchOne('SELECT iban FROM party_bank_account WHERE primary_flag IS NOT NULL'));
    }

    public function testNewColumnsGetTheirDefaults(): void
    {
        $this->legacyParty('Customer', true, false, 'Customer Street 1');

        $this->migrateTo(null);

        $row = $this->connection->fetchAssociative('SELECT * FROM party');
        self::assertIsArray($row);
        self::assertSame(14, $row['invoicing_due_days']);
        self::assertSame('CZK', $row['invoicing_currency']);
        self::assertSame('cs', $row['invoicing_language']);
        self::assertSame('bank_transfer', $row['invoicing_payment_method']);
        self::assertSame(0, $row['invoicing_reverse_charge']);
        self::assertSame('', $row['private_note']);
        self::assertNull($row['archived_at']);
        self::assertArrayNotHasKey('is_customer', $row);
        self::assertArrayNotHasKey('address_street', $row);
    }

    public function testStoredEmailsGetTheirComparisonKeyAndPartiesNoMobileOrWebsite(): void
    {
        $this->legacyParty('With email', true, false, 'Street 1', ' Invoices@Example.COM ');

        $this->migrateTo(null);

        self::assertSame([['Invoices@Example.COM', 'invoices@example.com']], $this->connection->fetchAllNumeric('SELECT address, address_key FROM party_email'));
        self::assertSame([['', '']], $this->connection->fetchAllNumeric('SELECT mobile, website FROM party'));

        $this->migrateTo('DoctrineMigrations\\Version20261031000001');
        $row = $this->connection->fetchAssociative('SELECT * FROM party_email');
        self::assertIsArray($row);
        self::assertArrayNotHasKey('address_key', $row);
    }

    public function testGoingDownRestoresTheBooleansAndTheAddress(): void
    {
        $this->legacyParty('Both', true, true, 'Both Street 3');
        $this->legacyParty('Supplier', false, true, 'Supplier Street 2');
        $this->migrateTo(null);

        $this->migrateTo(self::BEFORE);

        self::assertEquals(
            ['Both' => [1, 1, 'Both Street 3'], 'Supplier' => [0, 1, 'Supplier Street 2']],
            array_map(
                static fn (array $row): array => [$row['is_customer'], $row['is_supplier'], $row['address_street']],
                $this->connection->fetchAllAssociativeIndexed('SELECT display_name, is_customer, is_supplier, address_street FROM party'),
            ),
        );
    }

    private function legacyParty(string $name, bool $customer, bool $supplier, string $street, string $email = '', ?string $country = null, ?string $number = null): void
    {
        $this->connection->insert('party', [
            'tenant_id' => self::TENANT,
            'kind' => 'person',
            'display_name' => $name,
            'email' => $email,
            'registration_country' => $country,
            'registration_number' => $number,
            'phone' => '',
            'is_customer' => (int) $customer,
            'is_supplier' => (int) $supplier,
            'address_street' => $street,
            'address_city' => 'Example City',
            'address_postal_code' => '100 00',
            'address_country_code' => 'CZ',
        ]);
    }

    private function migrateTo(?string $version): void
    {
        // A fresh factory per run: executed migration instances are frozen.
        $migrations = DependencyFactory::fromConnection(
            new ConfigurationArray([
                'migrations_paths' => ['DoctrineMigrations' => \dirname(__DIR__, 3).'/migrations'],
            ]),
            new ExistingConnection($this->connection),
        );
        $migrations->getMetadataStorage()->ensureInitialized();
        $plan = $migrations->getMigrationPlanCalculator()->getPlanUntilVersion(
            null === $version ? $migrations->getVersionAliasResolver()->resolveVersionAlias('latest') : new Version($version),
        );
        $migrations->getMigrator()->migrate($plan, new MigratorConfiguration());
    }
}
