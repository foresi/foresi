<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Application;

/**
 * Runs a change in one database transaction under one audit correlation, and
 * hands its events to the outbox once it is committed.
 */
interface ActivitiesTransaction
{
    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(callable $work): mixed;

    /** Writes the changes made so far within the running transaction. */
    public function flush(): void;
}
