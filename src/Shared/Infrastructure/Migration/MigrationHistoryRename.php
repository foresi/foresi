<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Follows a module that changed its name in the history of executed
 * migrations. The class of a migration is its version, so a module that moves
 * to another namespace would run its migrations again; the rows of its
 * executed versions get the new namespace instead, in one transaction and
 * without DDL.
 *
 * A rename is due while any row still carries an old namespace. A database
 * that has both the old and the new row of one version was changed by hand
 * and is left alone.
 */
final readonly class MigrationHistoryRename
{
    /**
     * The old migration namespace of a module and its new one, each with the
     * trailing backslash. Add a line here when a module changes its name; a
     * line stays as long as a database may still carry the old rows.
     */
    public const array RENAMES = [
        'Foresi\\Purchasing\\Migrations\\' => 'Foresi\\ReceivedDocuments\\Migrations\\',
        'Foresi\\Sales\\Migrations\\' => 'Foresi\\Deals\\Migrations\\',
        'Foresi\\Documents\\Migrations\\' => 'Foresi\\DocumentTemplates\\Migrations\\',
    ];

    public function __construct(
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private DependencyFactory $migrations,
    ) {
    }

    /**
     * Where the history stands: the versions to rename, and the versions whose
     * old and new row both exist.
     *
     * @return array{due: array<string, string>, conflicts: list<string>}
     */
    public function plan(): array
    {
        $due = [];
        $conflicts = [];
        $executed = array_flip($this->executed());
        foreach (array_keys($executed) as $version) {
            $version = (string) $version;
            $renamed = self::renamed($version);
            if ($renamed === $version) {
                continue;
            }
            if (isset($executed[$renamed])) {
                $conflicts[] = $version;
            } else {
                $due[$version] = $renamed;
            }
        }
        ksort($due);
        sort($conflicts);

        return ['due' => $due, 'conflicts' => $conflicts];
    }

    /**
     * Rewrites the rows of the executed versions with an old namespace.
     *
     * @return array<string, string> the old version and the new one of each row
     *
     * @throws \LogicException when an old and a new row of one version both exist
     */
    public function rename(): array
    {
        $plan = $this->plan();
        if ([] !== $plan['conflicts']) {
            throw new \LogicException('Both the old and the new row exist for: '.implode(', ', $plan['conflicts']));
        }
        if ([] === $plan['due']) {
            return [];
        }
        $connection = $this->migrations->getConnection();
        $statement = \sprintf(
            'UPDATE %s SET %s = :new WHERE %s = :old',
            $connection->quoteSingleIdentifier($this->table()),
            $connection->quoteSingleIdentifier($this->versionColumn()),
            $connection->quoteSingleIdentifier($this->versionColumn()),
        );
        $connection->transactional(static function (Connection $connection) use ($plan, $statement): void {
            foreach ($plan['due'] as $old => $new) {
                $connection->executeStatement($statement, ['new' => $new, 'old' => (string) $old]);
            }
        });

        return $plan['due'];
    }

    /**
     * The version with the new namespace of its module, or the version itself.
     */
    public static function renamed(string $version): string
    {
        foreach (self::RENAMES as $old => $new) {
            if (str_starts_with($version, $old)) {
                return $new.substr($version, \strlen($old));
            }
        }

        return $version;
    }

    /**
     * @return list<string>
     */
    private function executed(): array
    {
        $connection = $this->migrations->getConnection();
        // A database that never migrated has no table of versions: there is nothing to rename.
        if (!$connection->createSchemaManager()->tablesExist([$this->table()])) {
            return [];
        }

        return array_values(array_map(
            'strval',
            $connection->fetchFirstColumn(\sprintf('SELECT %s FROM %s', $connection->quoteSingleIdentifier($this->versionColumn()), $connection->quoteSingleIdentifier($this->table()))),
        ));
    }

    private function table(): string
    {
        $storage = $this->migrations->getConfiguration()->getMetadataStorageConfiguration();

        return $storage instanceof TableMetadataStorageConfiguration ? $storage->getTableName() : 'doctrine_migration_versions';
    }

    private function versionColumn(): string
    {
        $storage = $this->migrations->getConfiguration()->getMetadataStorageConfiguration();

        return $storage instanceof TableMetadataStorageConfiguration ? $storage->getVersionColumnName() : 'version';
    }
}
