<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * An inbound message one worker took for processing, with what processing
 * needs of it: plain values, so a session started over keeps them.
 */
final readonly class ClaimedMessage
{
    /**
     * @param string $accountId the bank account of the feed the message came to
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $accountId,
        public string $rawKey,
        public string $rawSha256,
    ) {
    }
}
