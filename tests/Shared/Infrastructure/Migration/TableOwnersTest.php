<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Shared\Infrastructure\Migration\TableOwners;
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

    public function testEveryTableHasTheModuleThatCreatesIt(): void
    {
        $owners = $this->owners()->owners();

        self::assertCount(82, $owners);
        self::assertSame('shared', $owners['messenger_messages']);
        self::assertSame('events', $owners['event_log']);
        self::assertSame('events', $owners['event_consumption']);
        self::assertSame('identity', $owners['api_idempotency_key']);
        self::assertSame('webhooks', $owners['webhook_delivery']);
        self::assertSame('party', $owners['party']);
        self::assertSame('work', $owners['time_entry']);
        self::assertSame('timesheet', $owners['released_time']);
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
