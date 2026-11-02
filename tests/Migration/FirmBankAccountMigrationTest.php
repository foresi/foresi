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
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Type;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\TestCase;

/**
 * The JSON list of a firm's bank accounts becomes rows, and back: a scratch
 * database on SQLite, or a schema of its own in the test database on PostgreSQL.
 */
final class FirmBankAccountMigrationTest extends TestCase
{
    private const string BEFORE = 'DoctrineMigrations\Version20261102000090';
    private const string AFTER = 'DoctrineMigrations\Version20261103020002';
    private const string TENANT = '11111111-1111-1111-1111-111111111111';
    private const string SCHEMA = 'firm_bank_account_migration_test';
    private const string IBAN = 'CZ6508000000192000145399';
    private const string SECOND_IBAN = 'CZ5508000000001234567899';
    private const string THIRD_IBAN = 'DE89370400440532013000';

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
        $this->db->insert('tenant', ['id' => self::TENANT, 'name' => 'Example', 'created_at' => '2026-10-31 12:00:00']);
    }

    protected function tearDown(): void
    {
        if ($this->db->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->db->executeStatement('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
        }
        $this->db->close();
    }

    public function testTheJsonListBecomesRowsInOrderWithTheFirstPrimaryAndCzk(): void
    {
        $firm = $this->firm('12345695', [self::SECOND_IBAN, self::IBAN, self::THIRD_IBAN]);
        $other = $this->firm('12345687', [self::IBAN]);

        $this->migrateTo(self::AFTER);

        self::assertSame([
            [$firm, self::SECOND_IBAN, 'CZK', '', true, 0],
            [$firm, self::IBAN, 'CZK', '', false, 1],
            [$firm, self::THIRD_IBAN, 'CZK', '', false, 2],
            [$other, self::IBAN, 'CZK', '', true, 0],
        ], $this->rows());
        $tenants = $this->db->fetchFirstColumn('SELECT DISTINCT tenant_id FROM firm_bank_account');
        self::assertSame([self::TENANT], $tenants);
        foreach ($this->db->fetchFirstColumn('SELECT id FROM firm_bank_account') as $id) {
            self::assertTrue(Id::isValid(self::text($id)));
        }
        self::assertEquals(0, $this->db->fetchOne('SELECT COUNT(*) FROM firm_bank_account WHERE archived_at IS NOT NULL'));
        self::assertNotContains('bank_accounts', $this->firmColumns());
    }

    public function testAFirmOutsideCzechiaGetsItsCountrysCurrency(): void
    {
        $slovak = $this->firm('12345695', [self::THIRD_IBAN], 'SK');
        $american = $this->firm('12345687', [self::IBAN], 'US');
        // A country whose currency Foresi does not know keeps CZK, which the owner corrects.
        $british = $this->firm('12345679', [self::SECOND_IBAN], 'GB');

        $this->migrateTo(self::AFTER);

        $currencies = $this->db->fetchAllKeyValue('SELECT legal_entity_id, currency FROM firm_bank_account');
        self::assertSame([$slovak => 'EUR', $american => 'USD', $british => 'CZK'], [$slovak => $currencies[$slovak], $american => $currencies[$american], $british => $currencies[$british]]);
    }

    public function testAnEmptyListMakesNoRows(): void
    {
        $this->firm('12345695', []);

        $this->migrateTo(self::AFTER);

        self::assertSame([], $this->rows());
    }

    public function testAnIbanListedTwiceBecomesOneRow(): void
    {
        $firm = $this->firm('12345695', [self::IBAN, self::SECOND_IBAN, self::IBAN]);

        $this->migrateTo(self::AFTER);

        self::assertSame([[$firm, self::IBAN, 'CZK', '', true, 0], [$firm, self::SECOND_IBAN, 'CZK', '', false, 1]], $this->rows());
    }

    public function testDownRestoresTheJsonList(): void
    {
        $firm = $this->firm('12345695', [self::IBAN, self::SECOND_IBAN, self::THIRD_IBAN]);
        $empty = $this->firm('12345687', []);
        $this->migrateTo(self::AFTER);
        // What the owner did since: the third account became primary and the second was archived.
        $this->db->executeStatement('UPDATE firm_bank_account SET primary_flag = NULL WHERE iban = ?', [self::IBAN]);
        $this->db->executeStatement('UPDATE firm_bank_account SET primary_flag = ? WHERE iban = ?', [true, self::THIRD_IBAN], ['boolean', 'string']);
        $this->db->executeStatement('UPDATE firm_bank_account SET archived_at = ? WHERE iban = ?', ['2026-11-02 10:00:00', self::SECOND_IBAN]);

        $this->migrateTo(self::BEFORE);

        $lists = $this->db->fetchAllKeyValue('SELECT id, bank_accounts FROM legal_entity');
        self::assertSame([self::THIRD_IBAN, self::IBAN], json_decode(self::text($lists[$firm]), true));
        self::assertSame([], json_decode(self::text($lists[$empty]), true));
        self::assertTrue($this->db->createSchemaManager()->introspectTable('legal_entity')->getColumn('bank_accounts')->getNotnull());
        self::assertFalse($this->db->createSchemaManager()->tableExists('firm_bank_account'));

        $this->migrateTo(self::AFTER);
        self::assertSame([[$firm, self::THIRD_IBAN, 'CZK', '', true, 0], [$firm, self::IBAN, 'CZK', '', false, 1]], $this->rows(), 'it migrates up again');
    }

    /**
     * @return list<array{string, string, string, string, bool, int}>
     */
    private function rows(): array
    {
        $rows = $this->db->fetchAllNumeric('SELECT legal_entity_id, iban, currency, label, primary_flag, position FROM firm_bank_account ORDER BY legal_entity_id, position');

        return array_map(static function (array $row): array {
            self::assertIsInt($row[5]);

            return [self::text($row[0]), self::text($row[1]), self::text($row[2]), self::text($row[3]), (bool) $row[4], $row[5]];
        }, $rows);
    }

    /**
     * @return list<string>
     */
    private function firmColumns(): array
    {
        return array_map(static fn (Column $column): string => $column->getName(), $this->db->createSchemaManager()->introspectTable('legal_entity')->getColumns());
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * @param list<string> $ibans
     */
    private function firm(string $number, array $ibans, string $country = 'CZ'): string
    {
        $id = Id::generate();
        $this->db->insert('legal_entity', [
            'id' => $id,
            'tenant_id' => self::TENANT,
            'name' => 'Example Firm '.$number,
            'vat_status' => 'not_registered',
            'register_entry' => '',
            'bank_accounts' => json_encode($ibans, \JSON_THROW_ON_ERROR),
            'registration_country' => 'CZ',
            'registration_number' => $number,
            'active_registration_key' => $number,
            'address_street' => 'Example Street 1',
            'address_city' => 'Example City',
            'address_postal_code' => '100 00',
            'address_country_code' => $country,
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
