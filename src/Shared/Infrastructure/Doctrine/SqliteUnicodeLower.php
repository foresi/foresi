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

/**
 * SQLite folds the case of ASCII letters only, so LOWER('Čapek') stays 'Čapek'
 * and a case-insensitive search or sort misses names with diacritics.
 * This middleware replaces LOWER on SQLite connections with a Unicode-aware one.
 * PostgreSQL (and MySQL) already lower-case Unicode, so nothing changes there.
 */
#[AsMiddleware]
final class SqliteUnicodeLower implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(
                #[\SensitiveParameter]
                array $params,
            ): Driver\Connection {
                $connection = parent::connect($params);
                $native = $connection->getNativeConnection();
                $lower = static fn (?string $value): ?string => null === $value ? null : mb_strtolower($value);

                // DBAL opens PDO connections with PDO::connect() on PHP 8.4 and later,
                // which returns the driver subclass; PDO::sqliteCreateFunction() is deprecated.
                if ($native instanceof \Pdo\Sqlite) {
                    $native->createFunction('lower', $lower, 1);
                } elseif ($native instanceof \SQLite3) {
                    $native->createFunction('lower', $lower, 1);
                }

                return $connection;
            }
        };
    }
}
