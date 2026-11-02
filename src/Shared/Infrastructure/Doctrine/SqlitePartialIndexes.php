<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\ServerVersionProvider;

/**
 * SQLite has had partial indexes since 3.8, but DBAL writes an index's WHERE
 * only on PostgreSQL and reads it back nowhere on SQLite. This middleware gives
 * SQLite connections a platform that does both, so a partial index in the
 * mapping is the same index on SQLite and on PostgreSQL, and the schema tool and
 * the schema check see it as it is.
 */
#[AsMiddleware]
final class SqlitePartialIndexes implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
            {
                $platform = parent::getDatabasePlatform($versionProvider);

                return SQLitePlatform::class === $platform::class ? new SqlitePlatformWithPartialIndexes() : $platform;
            }
        };
    }
}
