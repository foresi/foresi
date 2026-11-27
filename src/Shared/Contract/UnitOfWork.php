<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Contract;

/**
 * Runs a module's writes as one unit (ADR 0048): its records and the contract
 * events it records with EventRecorder are stored together or not at all.
 *
 * A unit inside another one is part of it: the outermost unit flushes the
 * entity manager and commits. After the commit, the events reach listeners and
 * webhooks at once. Any exception from the work rolls the whole unit back, drops
 * its events, replaces the entity manager so the caller can query on (entities
 * loaded before are detached), and travels on unchanged. There is no retry.
 *
 * @since module API 1.10
 */
interface UnitOfWork
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
