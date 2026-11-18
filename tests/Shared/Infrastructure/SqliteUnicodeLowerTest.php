<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Foresi\Shared\Infrastructure\Doctrine\SqliteUnicodeLower;
use PHPUnit\Framework\TestCase;

final class SqliteUnicodeLowerTest extends TestCase
{
    public function testLowerFoldsNonAsciiLettersOnSqlite(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], new Configuration()->setMiddlewares([new SqliteUnicodeLower()]));

        self::assertSame('čapek', $connection->fetchOne('SELECT LOWER(?)', ['Čapek']));
        self::assertEquals(1, $connection->fetchOne('SELECT LOWER(?) LIKE ?', ['Čapek s.r.o.', '%čapek%']));
        self::assertNull($connection->fetchOne('SELECT LOWER(NULL)'));
    }
}
