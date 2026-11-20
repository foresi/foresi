<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
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
 * The firm column leaves the 22 module tables with their rows kept; the unique
 * indexes that led with it hold on their remaining columns, and the settings
 * tables hold one row. Going down gives every row the firm back, and the schema
 * is as it was. A scratch database on SQLite, or a schema of its own in the test
 * database on PostgreSQL.
 */
final class FirmColumnRemovalMigrationTest extends TestCase
{
    private const string BEFORE = 'DoctrineMigrations\Version20261120100001';
    private const string DROP = 'DoctrineMigrations\Version20261120300001';
    private const string SCHEMA = 'firm_column_removal_migration_test';
    private const string FIRM = '22222222-2222-2222-2222-222222222222';

    private const array TABLES = [
        'abandoned_number', 'bank_movement', 'cash_flow_minimum_balance', 'cash_flow_planned_item', 'evidence_series',
        'firm_bank_account', 'firm_bank_pairing', 'firm_invoicing', 'firm_mail_server', 'firm_reminders',
        'idoklad_connection', 'idoklad_import_run', 'idoklad_job', 'idoklad_link', 'idoklad_pull', 'idoklad_record',
        'invoice', 'mail_template', 'payment', 'received_document', 'recurring_invoice', 'series',
    ];

    private Connection $db;

    protected function setUp(): void
    {
        if (!Type::hasType('vat_id')) {
            Type::addType('vat_id', VatIdType::class);
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
        $this->firm();
        $this->series('33333333-3333-3333-3333-333333333333', 'FV');
        $this->series('44444444-4444-4444-4444-444444444444', 'ZF');
        $this->settings('55555555-5555-5555-5555-555555555555');

        $this->migrateTo(self::DROP);

        $tables = $this->db->createSchemaManager()->introspectSchema();
        foreach (self::TABLES as $table) {
            self::assertFalse($tables->getTable($table)->hasColumn('legal_entity_id'), $table);
            foreach ($tables->getTable($table)->getForeignKeys() as $key) {
                self::assertNotSame('legal_entity', $key->getForeignTableName(), $table);
            }
        }
        self::assertSame(['FV', 'ZF'], $this->db->fetchFirstColumn('SELECT name FROM series ORDER BY name'));
        self::assertSame(['55555555-5555-5555-5555-555555555555'], $this->db->fetchFirstColumn('SELECT id FROM firm_invoicing'));
        self::assertTrue((bool) $this->db->fetchOne('SELECT one_row FROM firm_invoicing'));
        self::assertSame(['number_key'], $tables->getTable('invoice')->getIndex('uniq_invoice_number')->getColumns());
        self::assertTrue($tables->getTable('invoice')->getIndex('uniq_invoice_number')->isUnique());
        self::assertSame(['issue_date'], $tables->getTable('invoice')->getIndex('idx_invoice_issue_date')->getColumns());
        self::assertSame(['kind', 'default_flag'], $tables->getTable('series')->getIndex('uniq_series_default')->getColumns());
        self::assertSame(['kind', 'language'], $tables->getTable('mail_template')->getIndex('uniq_mail_template_kind_language')->getColumns());
        self::assertSame(['primary_flag'], $tables->getTable('firm_bank_account')->getIndex('uniq_firm_bank_account_primary')->getColumns());
        self::assertSame(['one_row'], $tables->getTable('evidence_series')->getIndex('uniq_evidence_series_one_row')->getColumns());
        self::assertFalse($tables->getTable('invoice')->hasIndex('idx_invoice_firm_date'));
    }

    public function testTheNameOfASeriesStaysUniqueInTheInstance(): void
    {
        $this->firm();
        $this->migrateTo(self::DROP);
        $this->series('33333333-3333-3333-3333-333333333333', 'FV', withFirm: false);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->series('44444444-4444-4444-4444-444444444444', 'FV', withFirm: false);
    }

    public function testASecondRowOfSettingsIsRefused(): void
    {
        $this->migrateTo(self::DROP);
        $this->settings('55555555-5555-5555-5555-555555555555', withFirm: false);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->settings('66666666-6666-6666-6666-666666666666', withFirm: false);
    }

    public function testPostgresqlRefusesASettingsRowThatIsNotTheOne(): void
    {
        if (!$this->db->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            self::markTestSkipped('SQLite cannot add a check to a table; the unique index and the code keep one row.');
        }
        $this->migrateTo(self::DROP);
        $this->settings('55555555-5555-5555-5555-555555555555', withFirm: false);

        $this->expectException(DriverException::class);
        $this->settings('66666666-6666-6666-6666-666666666666', withFirm: false, oneRow: false);
    }

    public function testRowsGoBackDownWithTheFirmAndTheSchemaIsAsItWas(): void
    {
        $before = $this->db->createSchemaManager()->introspectSchema();
        $this->firm();
        $this->migrateTo(self::DROP);
        $this->series('33333333-3333-3333-3333-333333333333', 'FV', withFirm: false);
        $this->settings('55555555-5555-5555-5555-555555555555', withFirm: false);

        $this->migrateTo(self::BEFORE);

        $manager = $this->db->createSchemaManager();
        $after = $manager->introspectSchema();
        self::assertTrue($manager->createComparator()->compareSchemas($before, $after)->isEmpty(), 'The schema after going down differs from the one before going up.');
        self::assertSame([self::FIRM], $this->db->fetchFirstColumn('SELECT legal_entity_id FROM series'));
        self::assertSame([self::FIRM], $this->db->fetchFirstColumn('SELECT legal_entity_id FROM firm_invoicing'));
        foreach (self::TABLES as $table) {
            self::assertTrue($after->getTable($table)->getColumn('legal_entity_id')->getNotnull(), $table);
        }
    }

    public function testRowsWithoutAFirmStopTheWayBackDown(): void
    {
        $this->migrateTo(self::DROP);
        $this->settings('55555555-5555-5555-5555-555555555555', withFirm: false);

        try {
            $this->migrateTo(self::BEFORE);
            self::fail('Rows without a firm to give them must stop the way down.');
        } catch (AbortMigration $e) {
            self::assertStringContainsString('firm_invoicing holds rows', $e->getMessage());
        }

        self::assertSame(['55555555-5555-5555-5555-555555555555'], $this->db->fetchFirstColumn('SELECT id FROM firm_invoicing'));
    }

    public function testASecondFirmStopsTheWayUp(): void
    {
        $this->firm();
        $this->firm('77777777-7777-7777-7777-777777777777');

        $this->expectException(AbortMigration::class);
        $this->migrateTo(self::DROP);
    }

    private function firm(string $id = self::FIRM): void
    {
        $this->db->insert('legal_entity', [
            'id' => $id,
            'name' => 'Example Firm',
            'vat_status' => 'not_registered',
            'register_entry' => '',
            'registration_country' => 'CZ',
            'registration_number' => '12345678',
            'address_street' => 'Example Street 1',
            'address_city' => 'Example City',
            'address_postal_code' => '100 00',
            'address_country_code' => 'CZ',
        ]);
    }

    private function series(string $id, string $name, bool $withFirm = true): void
    {
        $this->db->insert('series', ($withFirm ? ['legal_entity_id' => self::FIRM] : []) + [
            'id' => $id,
            'name' => $name,
            'format' => $name.'{YYYY}{NNNN}',
            'kind' => 'invoice',
            'starts' => '{}',
            'archived' => false,
        ], ['archived' => ParameterType::BOOLEAN]);
    }

    private function settings(string $id, bool $withFirm = true, bool $oneRow = true): void
    {
        $this->db->insert('firm_invoicing', ($withFirm ? ['legal_entity_id' => self::FIRM] : ['one_row' => $oneRow]) + [
            'id' => $id,
            'rounding' => 'whole_unit',
            'constant_symbol' => '',
            'text_before' => '',
            'text_after' => '',
            'contact_email' => '',
            'contact_phone' => '',
            'contact_web' => '',
        ], ['one_row' => ParameterType::BOOLEAN]);
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
