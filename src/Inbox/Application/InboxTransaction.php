<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

/**
 * One transaction per change, with its events recorded inside it. When one is
 * open already, such as Mail's while it hands a message over, the work joins it
 * and is flushed into it.
 */
interface InboxTransaction
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
