<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/**
 * The steps of a migration that gives a module's stored names the module's new
 * id: its tables with their indexes and constraints, its events in the event
 * log, the webhook deliveries and the patterns of the webhook endpoints, and
 * the keys of its lists in the saved views. Each step leaves alone a table of a
 * module that is not installed. Going down, the events and lists are renamed
 * with old and new exchanged, and the tables with renameTablesBack().
 *
 * It runs on PostgreSQL and on SQLite, the database of the tests. SQLite cannot
 * rename an index, so the index is created again under the new name; nor can it
 * rename a foreign key, whose name SQLite does not keep apart from its table.
 */
final readonly class ModuleRename
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Renames tables, rows and all, then their indexes and foreign keys.
     *
     * @param array<string, string>                                    $tables      the new name by the old one
     * @param array<string, array{string, string, list<string>, bool}> $indexes     by the old name: the table's new name, the new name, the columns, whether it is unique
     * @param array<string, array{string, string}>                     $foreignKeys by the old name: the table's new name and the new name
     */
    public function renameTables(array $tables, array $indexes = [], array $foreignKeys = []): void
    {
        $this->rename($tables, $indexes, $foreignKeys);
    }

    /**
     * Gives the tables renameTables() renamed their old names again, with the same arguments.
     *
     * @param array<string, string>                                    $tables
     * @param array<string, array{string, string, list<string>, bool}> $indexes
     * @param array<string, array{string, string}>                     $foreignKeys
     */
    public function renameTablesBack(array $tables, array $indexes = [], array $foreignKeys = []): void
    {
        $back = array_flip($tables);
        $this->rename(
            $back,
            array_combine(array_column($indexes, 1), array_map(static fn (string $old, array $index): array => [$back[$index[0]], $old, $index[2], $index[3]], array_keys($indexes), $indexes)),
            array_combine(array_column($foreignKeys, 1), array_map(static fn (string $old, array $key): array => [$back[$key[0]], $old], array_keys($foreignKeys), $foreignKeys)),
        );
    }

    /**
     * @param array<string, string>                                    $tables
     * @param array<string, array{string, string, list<string>, bool}> $indexes
     * @param array<string, array{string, string}>                     $foreignKeys
     */
    private function rename(array $tables, array $indexes, array $foreignKeys): void
    {
        $postgres = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        foreach ($tables as $from => $to) {
            if (!$this->exists($from)) {
                continue;
            }
            $this->connection->executeStatement(\sprintf('ALTER TABLE %s RENAME TO %s', $from, $to));
            if ($postgres) {
                // PostgreSQL named the primary key after the table it was created as.
                $this->connection->executeStatement(\sprintf('ALTER INDEX IF EXISTS %s_pkey RENAME TO %s_pkey', $from, $to));
            }
        }
        foreach ($indexes as $from => [$table, $to, $columns, $unique]) {
            if (!$this->exists($table)) {
                continue;
            }
            if ($postgres) {
                $this->connection->executeStatement(\sprintf('ALTER INDEX IF EXISTS %s RENAME TO %s', $from, $to));
                continue;
            }
            $this->connection->executeStatement(\sprintf('DROP INDEX IF EXISTS %s', $from));
            $this->connection->executeStatement(\sprintf('CREATE %sINDEX %s ON %s (%s)', $unique ? 'UNIQUE ' : '', $to, $table, implode(', ', $columns)));
        }
        if (!$postgres) {
            return;
        }
        foreach ($foreignKeys as $from => [$table, $to]) {
            if ($this->exists($table)) {
                $this->connection->executeStatement(\sprintf('ALTER TABLE %s RENAME CONSTRAINT %s TO %s', $table, $from, $to));
            }
        }
    }

    /**
     * Gives the events whose names start with the old prefix ("sales.") the new
     * one ("deals."): in the event log, in the webhook deliveries, and in the
     * names and patterns ("sales.*") the webhook endpoints are subscribed to.
     * The bodies of the deliveries stay as they were sent.
     */
    public function renameEvents(string $from, string $to): void
    {
        foreach (['event_log' => 'name', 'webhook_delivery' => 'event_type'] as $table => $column) {
            if (!$this->exists($table)) {
                continue;
            }
            foreach ($this->connection->fetchFirstColumn(\sprintf('SELECT DISTINCT %s FROM %s', $column, $table)) as $name) {
                if (\is_string($name) && str_starts_with($name, $from)) {
                    $this->connection->executeStatement(\sprintf('UPDATE %s SET %s = ? WHERE %s = ?', $table, $column, $column), [$to.substr($name, \strlen($from)), $name]);
                }
            }
        }
        if (!$this->exists('webhook_endpoint')) {
            return;
        }
        foreach ($this->connection->fetchAllAssociative('SELECT id, event_types FROM webhook_endpoint') as $row) {
            $types = $this->names($row['event_types']);
            if (null === $types) {
                continue;
            }
            $renamed = array_values(array_unique(array_map(
                static fn (string $type): string => str_starts_with($type, $from) ? $to.substr($type, \strlen($from)) : $type,
                $types,
            )));
            if ($renamed !== $types) {
                $this->connection->update('webhook_endpoint', ['event_types' => json_encode($renamed, \JSON_THROW_ON_ERROR)], ['id' => $row['id']]);
            }
        }
    }

    /**
     * Gives the saved views of a list its new key, and the views it ships their
     * system keys under it ("sales.deals.open" becomes "deals.deals.open").
     *
     * @param array<string, string> $keys the new list key by the old one
     */
    public function renameListKeys(array $keys): void
    {
        if (!$this->exists('saved_view')) {
            return;
        }
        foreach ($keys as $from => $to) {
            $this->connection->executeStatement('UPDATE saved_view SET list_key = ? WHERE list_key = ?', [$to, $from]);
            foreach ($this->connection->fetchAllAssociative('SELECT id, system_key FROM saved_view WHERE system_key IS NOT NULL') as $row) {
                $key = $row['system_key'];
                if (\is_string($key) && str_starts_with($key, $from.'.')) {
                    $this->connection->update('saved_view', ['system_key' => $to.substr($key, \strlen($from))], ['id' => $row['id']]);
                }
            }
        }
    }

    private function exists(string $table): bool
    {
        return $this->connection->createSchemaManager()->tablesExist([$table]);
    }

    /**
     * @return list<string>|null null for a value that is no list of names
     */
    private function names(mixed $json): ?array
    {
        $names = \is_string($json) ? json_decode($json, true) : null;
        if (!\is_array($names) || !array_is_list($names)) {
            return null;
        }
        foreach ($names as $name) {
            if (!\is_string($name)) {
                return null;
            }
        }

        return $names;
    }
}
