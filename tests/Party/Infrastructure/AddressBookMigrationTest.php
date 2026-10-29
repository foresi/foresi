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

    private function legacyParty(string $name, bool $customer, bool $supplier, string $street): void
    {
        $this->connection->insert('party', [
            'tenant_id' => self::TENANT,
            'kind' => 'person',
            'display_name' => $name,
            'email' => 'testing@example.com',
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
