<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Contract;

/**
 * Finds the copies of a stored message that arrived under another purpose: the
 * same Message-ID with the same bytes, kept for an address whose purpose differs.
 * The copies are not merged; the consumer that shows the message may tell the
 * person it came twice.
 */
interface CrossPurposeDuplicates
{
    /**
     * Other stored messages with this message's Message-ID and bytes whose address
     * has another purpose, oldest first. None for an unknown message, one without
     * a Message-ID, or one that arrived under a single purpose only.
     *
     * @return list<array{messageId: string, purpose: string}>
     */
    public function of(string $messageId): array;
}
