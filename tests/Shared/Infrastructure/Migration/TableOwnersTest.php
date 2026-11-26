<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Shared\Domain\Module\ModuleManifest;
use Foresi\Shared\Infrastructure\Migration\TableOwners;
use Foresi\Shared\Infrastructure\Module\InstalledModules;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Every table belongs to the one module whose migrations create it, tables
 * without an entity included.
 */
final class TableOwnersTest extends KernelTestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $entityManager->getConnection();
        new SchemaTool($entityManager)->dropDatabase();
        self::assertSame(Command::SUCCESS, $this->console('doctrine:migrations:migrate')->getStatusCode());
    }

    protected function tearDown(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        new SchemaTool($entityManager)->dropDatabase();
        parent::tearDown();
    }

    /** Tables whose owner is known, by table: each is checked when its module is installed. */
    private const array KNOWN_OWNERS = [
        'messenger_messages' => 'shared',
        'event_log' => 'events',
        'event_consumption' => 'events',
        'api_idempotency_key' => 'identity',
        'webhook_delivery' => 'webhooks',
        'party' => 'party',
        'party_vat_status_period' => 'party',
        'time_entry' => 'work',
        'released_time' => 'timesheet',
        'documents_document' => 'document-templates',
        'exchange_rate' => 'exchange-rate',
        'exchange_rate_setting' => 'exchange-rate',
        'vat_settings' => 'vat',
        'vat_period_entry' => 'vat',
        'vat_payment_assignment' => 'vat',
        'activities_activity' => 'activities',
        'sales_deal' => 'deals',
    ];

    public function testEveryTableHasTheModuleThatCreatesIt(): void
    {
        $owners = $this->owners()->owners();

        // Exactly the tables the installed modules' migrations made, whichever modules those are.
        $tables = array_map(static fn (string $table): string => substr($table, (int) strrpos('.'.$table, '.')), $this->db->createSchemaManager()->listTableNames());
        self::assertSame($this->sorted(array_values(array_diff($tables, ['doctrine_migration_versions']))), array_keys($owners));
        $installed = array_map(static fn (ModuleManifest $module): string => $module->id->value, self::getContainer()->get(InstalledModules::class)->graph()->all());
        foreach (self::KNOWN_OWNERS as $table => $module) {
            if (\in_array($module, $installed, true)) {
                self::assertSame($module, $owners[$table] ?? null, $table);
            } else {
                self::assertArrayNotHasKey($table, $owners, 'A module that is not installed owns no table.');
            }
        }
        self::assertSame(array_keys($owners), $this->sorted(array_keys($owners)));
    }

    public function testTheTablesOfTheModulesHaveNoProblem(): void
    {
        // Only the entities the test environment maps for itself have no migration.
        foreach ($this->owners()->problems() as $problem) {
            self::assertMatchesRegularExpression('/^The table test_\w+ of Foresi\\\Tests\\\/', $problem);
        }
    }

    public function testATableNoModuleCreatesIsAProblem(): void
    {
        $this->db->executeStatement('CREATE TABLE stray (id INTEGER)');

        self::assertContains('The table stray is created by no migration of an installed module.', $this->owners()->problems());
        $check = $this->console('app:migrations:owners', ['--list' => true]);
        self::assertSame(Command::FAILURE, $check->getStatusCode());
        self::assertStringContainsString('messenger_messages', $check->getDisplay());
        self::assertStringContainsString('The table stray', $check->getDisplay());
    }

    private function owners(): TableOwners
    {
        return self::getContainer()->get(TableOwners::class);
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        sort($names, \SORT_STRING);

        return $names;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function console(string $name, array $input = []): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester(new Application($kernel)->find($name));
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }
}
