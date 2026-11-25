<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

/**
 * An inbound message one worker took for processing, with what processing
 * needs of it: plain values, so a session started over keeps them.
 */
final readonly class ClaimedMessage
{
    /**
     * @param string $accountId     the bank account of the feed the message came to
     * @param string $mailMessageId Mail's message, read through Mail's contract
     */
    public function __construct(
        public string $id,
        public string $accountId,
        public string $mailMessageId,
    ) {
    }
}
