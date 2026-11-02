<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * Port: how often handling a message of the operator mailbox failed, by its UID,
 * across polls. It need not survive everything: a count that is lost starts over,
 * and a message is only ever tried again.
 */
interface PollFailures
{
    /**
     * Counts one more failure of the message.
     *
     * @return int how many there are now
     */
    public function failed(string $uid): int;

    /**
     * Forgets the failures of a message that was handled, or moved away.
     */
    public function clear(string $uid): void;

    /**
     * The messages that failed at least this often, by UID.
     *
     * @return list<string>
     */
    public function atLeast(int $failures): array;
}
