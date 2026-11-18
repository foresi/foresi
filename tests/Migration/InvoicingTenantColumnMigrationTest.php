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
 * The tenant column leaves the invoicing tables with their rows kept, and the
 * indexes that led with it hold on their remaining columns, the unique ones
 * still unique. A scratch database on SQLite, or a schema of its own in the test
 * database on PostgreSQL.
 */
final class InvoicingTenantColumnMigrationTest extends TestCase
{
    private const string BEFORE = 'DoctrineMigrations\Version20261118000003';
    private const string DROP = 'DoctrineMigrations\Version20261118000004';
    private const string SCHEMA = 'invoicing_tenant_column_migration_test';
    private const string TENANT = '11111111-1111-1111-1111-111111111111';
    private const string FIRM = '22222222-2222-2222-2222-222222222222';

    private const array TABLES = [
        'invoice', 'invoice_line', 'invoice_send', 'series', 'series_counter', 'payment', 'payment_allocation',
        'recurring_invoice', 'recurring_invoice_line', 'firm_invoicing', 'firm_reminders', 'mail_template',
        'firm_mail_server', 'abandoned_number',
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
        $this->firm();
        $this->series('33333333-3333-3333-3333-333333333333', 'FV');
        $this->series('44444444-4444-4444-4444-444444444444', 'ZF');

        $this->migrateTo(self::DROP);

        $tables = $this->db->createSchemaManager()->introspectSchema();
        foreach (self::TABLES as $table) {
            self::assertFalse($tables->getTable($table)->hasColumn('tenant_id'), $table);
        }
        self::assertSame(['FV', 'ZF'], $this->db->fetchFirstColumn('SELECT name FROM series ORDER BY name'));
        $invoice = $tables->getTable('invoice');
        self::assertSame(['legal_entity_id', 'issue_date'], $invoice->getIndex('idx_invoice_firm_date')->getColumns());
        self::assertSame(['legal_entity_id', 'tax_point_date'], $invoice->getIndex('idx_invoice_firm_tax_point')->getColumns());
        self::assertSame(['recurring_invoice_id', 'recurring_occurrence'], $invoice->getIndex('uniq_invoice_occurrence')->getColumns());
        self::assertTrue($invoice->getIndex('uniq_invoice_occurrence')->isUnique());
        $send = $tables->getTable('invoice_send');
        self::assertSame(['status', 'created_at'], $send->getIndex('idx_invoice_send_status')->getColumns());
        self::assertSame(['invoice_id', 'reminder_step'], $send->getIndex('uniq_send_reminder_step')->getColumns());
        self::assertTrue($send->getIndex('uniq_send_reminder_step')->isUnique());
        self::assertSame(['legal_entity_id', 'received_on'], $tables->getTable('payment')->getIndex('idx_payment_firm_received')->getColumns());
        self::assertSame(['state', 'next_occurrence'], $tables->getTable('recurring_invoice')->getIndex('idx_recurring_invoice_due')->getColumns());
        self::assertSame(['legal_entity_id', 'name'], $tables->getTable('series')->getIndex('uniq_series_name')->getColumns());
        self::assertFalse($tables->getTable('invoice')->hasIndex('idx_invoice_tenant'));
    }

    public function testTheNameOfASeriesStaysUniqueInItsFirm(): void
    {
        $this->firm();
        $this->migrateTo(self::DROP);
        $this->series('33333333-3333-3333-3333-333333333333', 'FV', withTenant: false);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->series('44444444-4444-4444-4444-444444444444', 'FV', withTenant: false);
    }

    public function testEmptyTablesGoBackDownWithTheColumn(): void
    {
        $this->migrateTo(self::DROP);

        $this->migrateTo(self::BEFORE);

        $tables = $this->db->createSchemaManager()->introspectSchema();
        foreach (self::TABLES as $table) {
            self::assertTrue($tables->getTable($table)->getColumn('tenant_id')->getNotnull(), $table);
        }
        self::assertSame(['tenant_id', 'recurring_invoice_id', 'recurring_occurrence'], $tables->getTable('invoice')->getIndex('uniq_invoice_occurrence')->getColumns());
        self::assertSame(['tenant_id', 'legal_entity_id', 'name'], $tables->getTable('series')->getIndex('uniq_series_name')->getColumns());
        self::assertSame(['tenant_id'], $tables->getTable('invoice')->getIndex('idx_invoice_tenant')->getColumns());
    }

    public function testRowsStopTheWayBackDown(): void
    {
        $this->firm();
        $this->series('33333333-3333-3333-3333-333333333333', 'FV');
        $this->migrateTo(self::DROP);

        try {
            $this->migrateTo(self::BEFORE);
            self::fail('Rows without a tenant must stop the way down.');
        } catch (AbortMigration $e) {
            self::assertStringContainsString('series holds rows', $e->getMessage());
        }

        self::assertSame(['FV'], $this->db->fetchFirstColumn('SELECT name FROM series'));
    }

    private function firm(): void
    {
        // The firm keeps its tenant: Party loses the column later.
        $this->db->insert('legal_entity', [
            'id' => self::FIRM,
            'tenant_id' => self::TENANT,
            'name' => 'Example Firm',
            'vat_status' => 'not_registered',
            'register_entry' => '',
            'registration_country' => 'CZ',
            'registration_number' => '12345678',
            'active_registration_key' => '12345678',
            'address_street' => 'Example Street 1',
            'address_city' => 'Example City',
            'address_postal_code' => '100 00',
            'address_country_code' => 'CZ',
        ]);
    }

    private function series(string $id, string $name, bool $withTenant = true): void
    {
        $this->db->insert('series', ($withTenant ? ['tenant_id' => self::TENANT] : []) + [
            'id' => $id,
            'legal_entity_id' => self::FIRM,
            'name' => $name,
            'format' => $name.'{YYYY}{NNNN}',
            'kind' => 'invoice',
            'starts' => '{}',
            'archived' => false,
        ], ['archived' => ParameterType::BOOLEAN]);
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
