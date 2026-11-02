<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\SQLiteSchemaManager;

/**
 * SQLite as it is: it writes the WHERE of a partial index, and its schema
 * manager reads it back. See SqlitePartialIndexes.
 */
final class SqlitePlatformWithPartialIndexes extends SQLitePlatform
{
    public function supportsPartialIndexes(): bool
    {
        return true;
    }

    public function createSchemaManager(Connection $connection): SQLiteSchemaManager
    {
        return new SqliteSchemaManagerWithPartialIndexes($connection, $this);
    }
}
