<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Shared\Infrastructure\Module\InstalledModules;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Which module owns each table (ADR 0034): the module whose migrations create
 * it. The migrations of the installed modules are played on an empty schema
 * in the order they run, without a database, so a table created with raw SQL
 * instead of the schema API has no owner either.
 *
 * The problems are a table of the database that no installed module creates
 * (a table without an owner, or one of a module that is not installed) and an
 * entity whose table another module creates.
 */
final readonly class TableOwners
{
    public function __construct(
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private DependencyFactory $migrations,
        private EntityManagerInterface $entityManager,
        private InstalledModules $modules,
    ) {
    }

    /**
     * @return array<string, string> the owning module id of each table, by table name, sorted
     */
    public function owners(): array
    {
        $schema = new Schema();
        $owners = [];
        foreach ($this->migrations->getMigrationRepository()->getMigrations()->getItems() as $available) {
            $module = $this->moduleOf((string) $available->getVersion());
            $before = $this->tableNames($schema);
            $available->getMigration()->up($schema);
            foreach (array_diff($this->tableNames($schema), $before) as $table) {
                $owners[$this->unqualified($table)] = $module ?? (string) $available->getVersion();
            }
        }
        ksort($owners, \SORT_STRING);

        return $owners;
    }

    /**
     * @return list<string> sorted
     */
    public function problems(): array
    {
        $owners = $this->owners();
        $problems = [];
        $storage = $this->migrations->getConfiguration()->getMetadataStorageConfiguration();
        $versions = $storage instanceof TableMetadataStorageConfiguration ? $storage->getTableName() : 'doctrine_migration_versions';
        foreach ($this->entityManager->getConnection()->createSchemaManager()->listTableNames() as $table) {
            $table = $this->unqualified($table);
            if ($table !== $versions && !isset($owners[$table])) {
                $problems[] = \sprintf('The table %s is created by no migration of an installed module.', $table);
            }
        }
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
                continue;
            }
            $module = $this->moduleOf($metadata->getName());
            $table = $metadata->getTableName();
            $owner = $owners[$table] ?? null;
            if (null === $owner) {
                $problems[] = \sprintf('The table %s of %s is created by no migration of an installed module.', $table, $metadata->getName());
            } elseif (null !== $module && $owner !== $module) {
                $problems[] = \sprintf('The table %s of %s (module %s) is created by the migrations of %s.', $table, $metadata->getName(), $module, $owner);
            }
        }
        $problems = array_values(array_unique($problems));
        sort($problems, \SORT_STRING);

        return $problems;
    }

    /**
     * The id of the installed module whose namespace holds the class.
     */
    private function moduleOf(string $class): ?string
    {
        foreach ($this->modules->graph()->all() as $module) {
            if (str_starts_with($class, $module->namespace)) {
                return $module->id->value;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function tableNames(Schema $schema): array
    {
        return array_map(static fn (Table $table): string => $table->getName(), $schema->getTables());
    }

    private function unqualified(string $table): string
    {
        $table = str_replace('"', '', $table);

        return str_starts_with($table, 'public.') ? substr($table, 7) : $table;
    }
}
