<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Support;

use App\Import\Application\ImportLock;

/**
 * Wraps the real lock and fails the next few acquisitions, as a lock store or
 * database that is briefly unreachable would. Static, so a test reaches it
 * without the container; reset it after each test.
 */
final class FlakyImportLock implements ImportLock
{
    public static int $failures = 0;

    public function __construct(private readonly ImportLock $inner)
    {
    }

    public function synchronized(string $name, callable $work): mixed
    {
        if (self::$failures > 0) {
            --self::$failures;

            throw new \RuntimeException('The lock store is unreachable.');
        }

        return $this->inner->synchronized($name, $work);
    }
}
