<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application;

/** Serialises short decisions across requests and workers, for example which import of a target may run. */
interface ImportLock
{
    /**
     * Runs the work while holding the named lock, waiting for it when another process holds it.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function synchronized(string $name, callable $work): mixed;
}
