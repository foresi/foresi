<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Infrastructure\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Version\Version;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\CashFlow\Application\CashFlowStore;
use Foresi\CashFlow\Contract\ExpectedFlow;
use Foresi\CashFlow\Contract\PlannedVatMode;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\CashFlow\Infrastructure\Source\PlannedItemFlows;
use Foresi\CashFlow\Migrations\Version20261123120000;
use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Domain\VatStatus;
use Foresi\Party\Domain\VatStatusPeriod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * The planned items from before their VAT could be stated state none, so
 * the forecast counts them as it did, also for a VAT payer.
 */
final class PlannedItemVatMigrationTest extends KernelTestCase
{
    private const string ID = '01937f4e-0000-7000-8000-000000000001';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        Clock::set(new MockClock('2026-11-17 10:00:00', 'UTC'));
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testExistingItemsMigrateAsNoneAndNumbersDoNotChange(): void
    {
        $this->connection()->executeStatement('ALTER TABLE cash_flow_planned_item DROP COLUMN vat_rate');
        $this->connection()->executeStatement('ALTER TABLE cash_flow_planned_item DROP COLUMN vat_mode');
        $this->connection()->insert('cash_flow_planned_item', [
            'id' => self::ID, 'direction' => 'out', 'name' => 'Office rent', 'category' => 'rent', 'amount_minor' => 1_000_000, 'currency' => 'CZK',
            'counterparty_id' => null, 'first_on' => '2026-11-20', 'recurrence_unit' => 'month', 'recurrence_every' => 1, 'last_day_of_month' => 0,
            'recurrence_end' => 'never', 'end_on' => null, 'end_after' => null, 'included' => 1, 'skipped_on' => '[]',
            'match_account' => null, 'match_variable_symbol' => null, 'match_tolerance_minor' => null, 'created_at' => '2026-11-01 10:00:00',
        ]);

        $this->migrate(static fn (AbstractMigration $migration, Schema $schema) => $migration->up($schema));

        self::assertSame(['none', null], array_values((array) $this->connection()->fetchAssociative('SELECT vat_mode, vat_rate FROM cash_flow_planned_item')));
        $this->em->persist(new VatStatusPeriod(VatStatus::Registered, VatPeriodLength::Month, new \DateTimeImmutable('2026-01-01')));
        $this->em->flush();
        $item = $this->em->find(PlannedItem::class, self::ID);
        self::assertInstanceOf(PlannedItem::class, $item);
        self::assertSame([PlannedVatMode::None, null, null], [$item->vatMode(), $item->vatRate(), $item->vat()]);
        $today = new \DateTimeImmutable('2026-11-17', new \DateTimeZone('Europe/Prague'));
        $flows = iterator_to_array(self::getContainer()->get(PlannedItemFlows::class)->flows($today, $today->modify('+2 months'), $today), false);
        self::assertSame([[1_000_000, null], [1_000_000, null]], array_map(static fn (ExpectedFlow $flow): array => [$flow->amount->minor(), $flow->vat], $flows), 'A payer\'s rent still moves 10,000, with no VAT.');
        self::assertCount(1, self::getContainer()->get(CashFlowStore::class)->plannedItems());
    }

    public function testDownDropsTheVatColumns(): void
    {
        $this->migrate(static fn (AbstractMigration $migration, Schema $schema) => $migration->down($schema));

        $columns = array_keys($this->connection()->createSchemaManager()->introspectTableColumnsByUnquotedName('cash_flow_planned_item'));
        self::assertNotContains('vat_mode', $columns);
        self::assertNotContains('vat_rate', $columns);
    }

    /**
     * Applies what the migration changes in the schema to the database.
     *
     * @param \Closure(AbstractMigration, Schema): void $change
     */
    private function migrate(\Closure $change): void
    {
        $manager = $this->connection()->createSchemaManager();
        $from = $manager->introspectSchema();
        $to = clone $from;
        $change($this->migration(), $to);
        foreach ($this->connection()->getDatabasePlatform()->getAlterSchemaSQL($manager->createComparator()->compareSchemas($from, $to)) as $sql) {
            $this->connection()->executeStatement($sql);
        }
    }

    /**
     * Loaded by Doctrine from the module's migrations directory, as a deploy loads it.
     */
    private function migration(): AbstractMigration
    {
        /** @var DependencyFactory $factory */
        $factory = self::getContainer()->get('doctrine.migrations.dependency_factory');
        $class = $factory->getMigrationRepository()->getMigration(new Version(Version20261123120000::class))->getMigration()::class;

        return new $class($this->connection(), $factory->getLogger());
    }

    private function connection(): Connection
    {
        return $this->em->getConnection();
    }
}
