<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\ExecutionResult;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Shared\Infrastructure\Console\RenameMigrationsCommand;
use Foresi\Shared\Infrastructure\Migration\MigrationHistoryRename;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A module that changed its name keeps its executed migrations: the version
 * rows get the new namespace and nothing else changes.
 */
final class MigrationHistoryRenameTest extends KernelTestCase
{
    private Connection $db;

    /** @var list<string> the versions of a database migrated with the current names */
    private array $current;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $entityManager->getConnection();
        new SchemaTool($entityManager)->dropDatabase();
        // The history of a database that executed every migration of the installed modules, without running them.
        $migrations = self::getContainer()->get('doctrine.migrations.dependency_factory');
        $migrations->getMetadataStorage()->ensureInitialized();
        $now = new \DateTimeImmutable();
        foreach ($migrations->getMigrationRepository()->getMigrations()->getItems() as $migration) {
            $migrations->getMetadataStorage()->complete(new ExecutionResult($migration->getVersion(), Direction::UP, $now));
        }
        $this->current = $this->versions();
    }

    protected function tearDown(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        new SchemaTool($entityManager)->dropDatabase();
        parent::tearDown();
    }

    public function testTheTargetsOfTheMapAreTheNamespacesOfRealMigrations(): void
    {
        foreach (MigrationHistoryRename::RENAMES as $new) {
            self::assertNotSame([], array_filter($this->current, static fn (string $version): bool => str_starts_with($version, $new)), $new);
        }
    }

    public function testADatabaseWithTheOldNamesIsRenamedInOneGo(): void
    {
        $this->useOldNames();
        $old = $this->versions();
        self::assertNotSame($this->current, $old);

        self::assertSame(RenameMigrationsCommand::PENDING, $this->console('app:migrations:rename', ['--check' => true])->getStatusCode());
        self::assertSame($old, $this->versions(), 'The check changes nothing.');

        $rename = $this->console('app:migrations:rename');

        self::assertSame(Command::SUCCESS, $rename->getStatusCode(), $rename->getDisplay());
        self::assertSame($this->current, $this->versions());
        // What ops/deploy.sh runs next finds every executed version among the available ones.
        self::assertSame(Command::SUCCESS, $this->console('doctrine:migrations:up-to-date')->getStatusCode());
        self::assertSame(Command::SUCCESS, $this->console('app:migrations:rename', ['--check' => true])->getStatusCode());
    }

    public function testRenamingTwiceChangesNothingTheSecondTime(): void
    {
        $this->useOldNames();
        self::assertSame(Command::SUCCESS, $this->console('app:migrations:rename')->getStatusCode());

        $again = $this->console('app:migrations:rename');

        self::assertSame(Command::SUCCESS, $again->getStatusCode());
        self::assertStringContainsString('nothing to do', $again->getDisplay());
        self::assertSame($this->current, $this->versions());
    }

    public function testADatabaseWithTheCurrentNamesNeedsNoRename(): void
    {
        self::assertSame(Command::SUCCESS, $this->console('app:migrations:rename', ['--check' => true])->getStatusCode());
        self::assertSame(Command::SUCCESS, $this->console('app:migrations:rename')->getStatusCode());
        self::assertSame($this->current, $this->versions());
    }

    public function testADatabaseThatNeverMigratedNeedsNoRename(): void
    {
        $this->db->executeStatement('DROP TABLE doctrine_migration_versions');

        self::assertSame(Command::SUCCESS, $this->console('app:migrations:rename', ['--check' => true])->getStatusCode());
        self::assertSame(Command::SUCCESS, $this->console('app:migrations:rename')->getStatusCode());
    }

    public function testAnOldAndANewRowOfOneVersionAreRefused(): void
    {
        $new = $this->versionsStartingWith('Foresi\\Deals\\Migrations\\')[0];
        $old = 'Foresi\\Sales\\Migrations\\'.substr($new, \strlen('Foresi\\Deals\\Migrations\\'));
        $this->db->insert('doctrine_migration_versions', ['version' => $old, 'executed_at' => '2026-11-20 12:00:00', 'execution_time' => 5]);
        $versions = $this->versions();

        self::assertSame(Command::FAILURE, $this->console('app:migrations:rename', ['--check' => true])->getStatusCode());
        $rename = $this->console('app:migrations:rename');

        self::assertSame(Command::FAILURE, $rename->getStatusCode());
        self::assertStringContainsString('The database was not changed.', $rename->getDisplay());
        self::assertSame($versions, $this->versions());
    }

    public function testOnlyTheRowsOfTheRenamedModulesChange(): void
    {
        $this->useOldNames();
        $other = array_values(array_filter($this->versions(), static fn (string $version): bool => !str_starts_with($version, 'Foresi\\Sales\\') && !str_starts_with($version, 'Foresi\\Purchasing\\') && !str_starts_with($version, 'Foresi\\Documents\\')));

        self::assertSame(Command::SUCCESS, $this->console('app:migrations:rename')->getStatusCode());

        foreach ($other as $version) {
            self::assertContains($version, $this->versions());
        }
        self::assertCount(\count($this->current), $this->versions());
    }

    /**
     * What a database migrated before the rename holds: the executed versions of the renamed modules under their old namespaces.
     */
    private function useOldNames(): void
    {
        foreach (MigrationHistoryRename::RENAMES as $old => $new) {
            foreach ($this->versionsStartingWith($new) as $version) {
                $this->db->update('doctrine_migration_versions', ['version' => $old.substr($version, \strlen($new))], ['version' => $version]);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function versionsStartingWith(string $namespace): array
    {
        return array_values(array_filter($this->versions(), static fn (string $version): bool => str_starts_with($version, $namespace)));
    }

    /**
     * @return list<string>
     */
    private function versions(): array
    {
        $versions = array_map('strval', $this->db->fetchFirstColumn('SELECT version FROM doctrine_migration_versions'));
        sort($versions);

        return $versions;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function console(string $command, array $input = []): CommandTester
    {
        $tester = new CommandTester(new Application(self::$kernel)->find($command));
        $tester->execute($input);

        return $tester;
    }
}
