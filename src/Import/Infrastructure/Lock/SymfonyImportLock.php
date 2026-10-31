<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Lock;

use App\Import\Application\ImportLock;
use Symfony\Component\Lock\LockFactory;

/**
 * Holds a Symfony lock (store from LOCK_DSN) for the length of one decision. The
 * decisions are short database transactions, so the TTL is only a safety net
 * for a process that dies holding the lock.
 */
final readonly class SymfonyImportLock implements ImportLock
{
    private const float TTL_SECONDS = 60.0;

    public function __construct(private LockFactory $locks)
    {
    }

    public function synchronized(string $name, callable $work): mixed
    {
        $lock = $this->locks->createLock($name, self::TTL_SECONDS);
        $lock->acquire(true);
        try {
            return $work();
        } finally {
            $lock->release();
        }
    }
}
