<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Part of a transaction that may fail on its own. What the transaction holds so
 * far is stored first; the work then runs to a savepoint, and when it throws, the
 * transaction goes back to the savepoint, the session starts over, and the failure
 * travels on for the caller to handle while the rest of the transaction stands.
 * Call it inside TransactionalWork.
 */
interface Savepoint
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
