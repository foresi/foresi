<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\DBAL\Schema\SQLiteSchemaManager;

/**
 * Reads the WHERE of a partial index from the statement SQLite keeps for it,
 * which the pragmas DBAL reads do not give. See SqlitePartialIndexes.
 */
final class SqliteSchemaManagerWithPartialIndexes extends SQLiteSchemaManager
{
    /** The condition after the last WHERE outside the column list of CREATE INDEX. */
    private const string CONDITION = '/\)\s*WHERE\s+(.+?)\s*;?\s*\z/is';

    protected function fetchIndexColumns(string $databaseName, ?string $tableName = null): array
    {
        $conditions = [];
        foreach ($this->connection->fetchAllAssociative("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL") as $index) {
            if (\is_string($index['name']) && \is_string($index['sql']) && 1 === preg_match(self::CONDITION, $index['sql'], $match)) {
                $conditions[strtolower($index['name'])] = $match[1];
            }
        }

        $rows = parent::fetchIndexColumns($databaseName, $tableName);
        foreach ($rows as $at => $row) {
            $name = $row['key_name'] ?? null;
            if (\is_string($name) && \array_key_exists(strtolower($name), $conditions)) {
                $rows[$at]['where'] = $conditions[strtolower($name)];
            }
        }

        return $rows;
    }
}
