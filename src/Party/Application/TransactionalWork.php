<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

/**
 * Runs a unit of work in one database transaction: everything it changes is
 * stored together or, when it throws, not at all. A failed unit of work also
 * leaves a usable persistence session behind (everything loaded before it is
 * detached), so the caller can handle the error and keep querying.
 */
interface TransactionalWork
{
    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(callable $work): mixed;
}
