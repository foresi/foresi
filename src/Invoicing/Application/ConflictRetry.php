<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Runs a whole save again when it lost to another one: on a lock error, or on a
 * unique index, which a second look at the data answers properly. A failed
 * TransactionalWork detaches everything, so the unit starts at loading by id.
 */
interface ConflictRetry
{
    /**
     * @template T
     *
     * @param callable(): T $unit
     *
     * @return T
     */
    public function run(callable $unit): mixed;
}
