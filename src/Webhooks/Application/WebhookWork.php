<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application;

/**
 * Runs a unit of work in one database transaction: what it changes, its audit
 * entries and the messages it queues are stored together or not at all. The
 * modules' TransactionalWork lives in Party, which a core module may not
 * require; this is the same unit.
 */
interface WebhookWork
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
