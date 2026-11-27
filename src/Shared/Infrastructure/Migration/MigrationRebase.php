<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Migration;

use Doctrine\DBAL\Schema\AbstractAsset;
use Doctrine\DBAL\Schema\AbstractNamedObject;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\ExecutedMigration;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\ExecutionResult;
use Doctrine\Migrations\Version\Version;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\SchemaValidator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Moves a database migrated with the global history onto the module
 * baselines (ADR 0034). The schema is already what the baselines create, so
 * only the rows of the executed versions change: the global versions go and
 * the baselines of the installed modules come, in one transaction, and no
 * DDL runs. It acts only on a database that executed every global version and
 * nothing else, and whose schema is in sync with the mapping. The tables that
 * a migration after the baselines creates, such as those of a module added
 * later, or changes, such as a column added to a table of a baseline, are left
 * out of that comparison: such a database has never had them as the mapping
 * has them, and doctrine:migrations:migrate brings them there after the rebase.
 */
final readonly class MigrationRebase
{
    public function __construct(
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private DependencyFactory $migrations,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Where the database stands, read from its executed versions only.
     */
    public function state(): RebaseState
    {
        $executed = $this->executed();
        if ([] === $executed) {
            return RebaseState::Fresh;
        }
        $global = array_intersect($executed, GlobalMigrationHistory::VERSIONS);
        if ([] === $global) {
            return RebaseState::Rebased;
        }

        return [] === $this->missing($executed) && [] === $this->unknown($executed)
            ? RebaseState::Global
            : RebaseState::Mixed;
    }

    /**
     * Why the database cannot be rebased, or nothing when it can: its state,
     * the global versions it has not executed, the versions it executed that
     * are not global, and a schema out of sync with the mapping.
     *
     * @return list<string>
     */
    public function refusals(): array
    {
        $state = $this->state();
        if (RebaseState::Fresh === $state) {
            return ['The database has executed no migration; a new database runs doctrine:migrations:migrate.'];
        }
        if (RebaseState::Rebased === $state) {
            return ['The database is already on the module baselines.'];
        }
        $executed = $this->executed();
        $refusals = [];
        foreach ($this->missing($executed) as $version) {
            $refusals[] = \sprintf('The global migration %s has not been executed; deploy the last release before the module packages and migrate first.', $version);
        }
        foreach ($this->unknown($executed) as $version) {
            $refusals[] = \sprintf('The migration %s is not part of the global history.', $version);
        }
        if ([] !== $refusals) {
            return $refusals;
        }
        $validator = new SchemaValidator($this->entityManager);
        foreach ($validator->validateMapping() as $class => $errors) {
            foreach ($errors as $error) {
                $refusals[] = \sprintf('The mapping of %s is invalid: %s', $class, $error);
            }
        }
        if (!$this->inSync()) {
            $refusals[] = 'The database schema is not in sync with the mapping (see doctrine:schema:validate).';
        }
        if ([] === $this->baselines()) {
            $refusals[] = 'No installed module has a baseline migration.';
        }

        return $refusals;
    }

    /**
     * Replaces the rows of the global versions with those of the baselines.
     *
     * @return array{removed: int, added: list<string>}
     *
     * @throws \LogicException when the database cannot be rebased
     */
    public function rebase(): array
    {
        $refusals = $this->refusals();
        if ([] !== $refusals) {
            throw new \LogicException(implode("\n", $refusals));
        }
        $storage = $this->migrations->getMetadataStorage();
        $baselines = $this->baselines();
        $now = new \DateTimeImmutable();
        $this->entityManager->getConnection()->transactional(static function () use ($storage, $baselines, $now): void {
            foreach (GlobalMigrationHistory::VERSIONS as $version) {
                $storage->complete(new ExecutionResult(new Version($version), Direction::DOWN));
            }
            foreach ($baselines as $version) {
                $storage->complete(new ExecutionResult(new Version($version), Direction::UP, $now));
            }
        });

        return ['removed' => \count(GlobalMigrationHistory::VERSIONS), 'added' => $baselines];
    }

    /**
     * Whether the schema matches the mapping, leaving out the table of the
     * executed versions as doctrine:schema:validate does, and the entities whose
     * tables migrations after the baselines create or change.
     */
    private function inSync(): bool
    {
        $later = $this->tablesAfterTheBaselines();
        $classes = array_values(array_filter(
            $this->entityManager->getMetadataFactory()->getAllMetadata(),
            static fn (ClassMetadata $metadata): bool => !\in_array($metadata->getTableName(), $later, true),
        ));
        $storage = $this->migrations->getConfiguration()->getMetadataStorageConfiguration();
        $table = $storage instanceof TableMetadataStorageConfiguration ? $storage->getTableName() : 'doctrine_migration_versions';
        $configuration = $this->entityManager->getConnection()->getConfiguration();
        $filter = $configuration->getSchemaAssetsFilter();
        // The changed tables are there in the database, so they leave the comparison by name too.
        $configuration->setSchemaAssetsFilter(static function (mixed $asset) use ($filter, $table, $later): bool {
            $name = match (true) {
                $asset instanceof AbstractNamedObject => $asset->getObjectName()->toString(),
                $asset instanceof AbstractAsset => $asset->getName(),
                default => \is_string($asset) ? $asset : '',
            };

            return $name !== $table && !\in_array($name, $later, true) && true === $filter($asset);
        });
        try {
            return [] === new SchemaTool($this->entityManager)->getUpdateSchemaSql($classes);
        } finally {
            $configuration->setSchemaAssetsFilter($filter);
        }
    }

    /**
     * The tables that migrations after the baselines create or change the
     * columns of, played in order on an empty schema.
     *
     * @return list<string>
     */
    private function tablesAfterTheBaselines(): array
    {
        $schema = new Schema();
        $tables = [];
        foreach ($this->migrations->getMigrationRepository()->getMigrations()->getItems() as $available) {
            $before = $this->columnsByTable($schema);
            $migration = $available->getMigration();
            $migration->up($schema);
            if ($migration instanceof RenamesTables) {
                $schema = TableOwners::renamed($schema, $migration);
                array_push($tables, ...array_values($migration->renamedTables()));
            }
            if (str_ends_with((string) $available->getVersion(), '\\'.GlobalMigrationHistory::BASELINE)) {
                continue;
            }
            foreach ($this->columnsByTable($schema) as $table => $columns) {
                if (($before[$table] ?? null) !== $columns) {
                    $tables[] = $table;
                }
            }
        }

        return $tables;
    }

    /**
     * @return array<string, list<string>> the column names of each table
     */
    private function columnsByTable(Schema $schema): array
    {
        $columns = [];
        foreach ($schema->getTables() as $table) {
            $columns[$table->getName()] = array_map(static fn (Column $column): string => $column->getName(), $table->getColumns());
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function executed(): array
    {
        return array_values(array_map(
            static fn (ExecutedMigration $migration): string => (string) $migration->getVersion(),
            $this->migrations->getMetadataStorage()->getExecutedMigrations()->getItems(),
        ));
    }

    /**
     * The baseline of every installed module, in the order they would run.
     *
     * @return list<string>
     */
    private function baselines(): array
    {
        $baselines = [];
        foreach ($this->migrations->getMigrationRepository()->getMigrations()->getItems() as $migration) {
            $version = (string) $migration->getVersion();
            if (str_ends_with($version, '\\'.GlobalMigrationHistory::BASELINE)) {
                $baselines[] = $version;
            }
        }
        $comparator = $this->migrations->getVersionComparator();
        usort($baselines, static fn (string $a, string $b): int => $comparator->compare(new Version($a), new Version($b)));

        return $baselines;
    }

    /**
     * @param list<string> $executed
     *
     * @return list<string>
     */
    private function missing(array $executed): array
    {
        return array_values(array_diff(GlobalMigrationHistory::VERSIONS, $executed));
    }

    /**
     * @param list<string> $executed
     *
     * @return list<string>
     */
    private function unknown(array $executed): array
    {
        return array_values(array_diff($executed, GlobalMigrationHistory::VERSIONS));
    }
}
