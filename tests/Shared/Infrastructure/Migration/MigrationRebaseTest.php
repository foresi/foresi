<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Shared\Infrastructure\Console\RebaseMigrationsCommand;
use Foresi\Shared\Infrastructure\Migration\GlobalMigrationHistory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A database on the global migration history moves onto the module baselines
 * by its version rows alone, and only from the one state that allows it. The
 * full proof against the last release with the history is
 * bin/migration-rebase-proof in CI.
 */
final class MigrationRebaseTest extends KernelTestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $entityManager->getConnection();
        new SchemaTool($entityManager)->dropDatabase();
        self::assertSame(Command::SUCCESS, $this->console('doctrine:migrations:migrate')->getStatusCode());
        // The test environment maps entities of its own and keeps messages in memory, so
        // the schema follows that mapping, as the schema of an application follows its own.
        $configuration = $this->db->getConfiguration();
        $filter = $configuration->getSchemaAssetsFilter();
        $configuration->setSchemaAssetsFilter(static fn (mixed $asset): bool => !\is_string($asset) || 'doctrine_migration_versions' !== $asset);
        new SchemaTool($entityManager)->updateSchema($entityManager->getMetadataFactory()->getAllMetadata());
        $configuration->setSchemaAssetsFilter($filter);
    }

    protected function tearDown(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        new SchemaTool($entityManager)->dropDatabase();
        parent::tearDown();
    }

    public function testADatabaseOnTheGlobalHistoryMovesOntoTheBaselines(): void
    {
        $tables = $this->tables();
        $this->onGlobalHistory();
        self::assertSame(RebaseMigrationsCommand::PENDING, $this->console('app:migrations:rebase', ['--check' => true])->getStatusCode());

        $rebase = $this->console('app:migrations:rebase');

        self::assertSame(Command::SUCCESS, $rebase->getStatusCode(), $rebase->getDisplay());
        self::assertStringContainsString('Replaced the 108 versions', $rebase->getDisplay());
        self::assertSame($this->baselines(), $this->versions());
        // What ops/deploy.sh runs next: the migrations after the baselines, which create what the database never had.
        self::assertSame(Command::SUCCESS, $this->console('doctrine:migrations:migrate')->getStatusCode());
        self::assertSame($tables, $this->tables());
        self::assertSame(Command::SUCCESS, $this->console('doctrine:migrations:up-to-date')->getStatusCode());
        self::assertSame(Command::SUCCESS, $this->console('app:migrations:rebase', ['--check' => true])->getStatusCode());
    }

    public function testADatabaseOnTheBaselinesIsLeftAlone(): void
    {
        $versions = $this->versions();

        $rebase = $this->console('app:migrations:rebase');

        self::assertSame(Command::SUCCESS, $rebase->getStatusCode());
        self::assertStringContainsString('already on the module baselines', $rebase->getDisplay());
        self::assertSame($versions, $this->versions());
        self::assertStringContainsString('rebased', $this->console('app:migrations:rebase', ['--check' => true])->getDisplay());
    }

    public function testANewDatabaseIsRefused(): void
    {
        $this->db->executeStatement('DELETE FROM doctrine_migration_versions');

        self::assertSame(Command::SUCCESS, $this->console('app:migrations:rebase', ['--check' => true])->getStatusCode());
        $rebase = $this->console('app:migrations:rebase');

        self::assertSame(Command::FAILURE, $rebase->getStatusCode());
        self::assertStringContainsString('executed no migration', $rebase->getDisplay());
    }

    public function testAMissingGlobalVersionIsRefused(): void
    {
        $this->onGlobalHistory();
        $this->db->delete('doctrine_migration_versions', ['version' => GlobalMigrationHistory::VERSIONS[5]]);

        $this->assertRefused(GlobalMigrationHistory::VERSIONS[5]);
    }

    public function testAVersionOutsideTheGlobalHistoryIsRefused(): void
    {
        $this->onGlobalHistory();
        $this->db->insert('doctrine_migration_versions', ['version' => 'DoctrineMigrations\Version20991231000000']);

        $this->assertRefused('DoctrineMigrations\Version20991231000000');
    }

    public function testASchemaOutOfSyncWithTheMappingIsRefused(): void
    {
        $this->onGlobalHistory();
        $this->db->executeStatement('DROP INDEX idx_saved_view_list');

        // The check reads the version rows only, so the deploy leaves such a database alone too.
        $this->assertRefused('not in sync with the mapping', RebaseMigrationsCommand::PENDING);
    }

    private function assertRefused(string $reason, int $check = Command::FAILURE): void
    {
        $versions = $this->versions();

        self::assertSame($check, $this->console('app:migrations:rebase', ['--check' => true])->getStatusCode());
        $rebase = $this->console('app:migrations:rebase');

        self::assertSame(Command::FAILURE, $rebase->getStatusCode());
        self::assertStringContainsString($reason, preg_replace('/\s+/', ' ', $rebase->getDisplay()) ?? '');
        self::assertStringContainsString('The database was not changed.', $rebase->getDisplay());
        self::assertSame($versions, $this->versions());
    }

    /**
     * The rows a database migrated before the module packages holds.
     */
    /**
     * A database of the last release before the module packages: every global version executed,
     * and none of the tables that only migrations after the baselines create.
     */
    private function onGlobalHistory(): void
    {
        // Every migration in order on one schema, as the database got them: what one after
        // the baselines creates goes, and the columns it adds to a table that was there go too.
        $repository = self::getContainer()->get('doctrine.migrations.dependency_factory')->getMigrationRepository();
        $afterBaselines = $this->afterBaselines();
        $schema = new \Doctrine\DBAL\Schema\Schema();
        $dropTables = [];
        $dropColumns = [];
        foreach ($this->migrations() as $version) {
            $before = $this->columnsByTable($schema);
            $repository->getMigration(new \Doctrine\Migrations\Version\Version($version))->getMigration()->up($schema);
            if (!\in_array($version, $afterBaselines, true)) {
                continue;
            }
            foreach ($this->columnsByTable($schema) as $table => $columns) {
                if (!\array_key_exists($table, $before)) {
                    $dropTables[] = $table;
                } elseif (!\in_array($table, $dropTables, true)) {
                    foreach (array_diff($columns, $before[$table]) as $column) {
                        $dropColumns[] = [$table, $column];
                    }
                }
            }
        }
        foreach ($dropColumns as [$table, $column]) {
            $this->db->executeStatement(\sprintf('ALTER TABLE %s DROP COLUMN %s', $table, $column));
        }
        foreach ($dropTables as $table) {
            $this->db->createSchemaManager()->dropTable($table);
        }
        $this->db->executeStatement('DELETE FROM doctrine_migration_versions');
        foreach (GlobalMigrationHistory::VERSIONS as $version) {
            $this->db->insert('doctrine_migration_versions', ['version' => $version, 'executed_at' => '2026-11-20 12:00:00', 'execution_time' => 5]);
        }
    }

    /**
     * @return array<string, list<string>> the column names of each table
     */
    private function columnsByTable(\Doctrine\DBAL\Schema\Schema $schema): array
    {
        $columns = [];
        foreach ($schema->getTables() as $table) {
            $columns[$table->getName()] = array_map(static fn (\Doctrine\DBAL\Schema\Column $column): string => $column->getName(), $table->getColumns());
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function baselines(): array
    {
        return array_values(array_filter($this->migrations(), static fn (string $version): bool => str_ends_with($version, '\\'.GlobalMigrationHistory::BASELINE)));
    }

    /**
     * The migrations written after the baselines, such as those of a module added later.
     *
     * @return list<string>
     */
    private function afterBaselines(): array
    {
        return array_values(array_diff($this->migrations(), $this->baselines()));
    }

    /**
     * @return list<string> every migration of the modules in src/
     */
    private function migrations(): array
    {
        $files = glob(\dirname(__DIR__, 4).'/src/*/migrations/Version*.php');
        self::assertIsArray($files);
        $versions = array_map(
            static fn (string $file): string => 'Foresi\\'.basename(\dirname($file, 2)).'\Migrations\\'.basename($file, '.php'),
            $files,
        );
        sort($versions, \SORT_STRING);

        return $versions;
    }

    /**
     * @return list<string>
     */
    private function versions(): array
    {
        $versions = array_map(static fn (mixed $version): string => \is_string($version) ? $version : '', $this->db->fetchFirstColumn('SELECT version FROM doctrine_migration_versions'));
        sort($versions, \SORT_STRING);

        return $versions;
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        $tables = $this->db->createSchemaManager()->listTableNames();
        sort($tables, \SORT_STRING);

        return $tables;
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
