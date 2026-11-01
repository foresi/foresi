<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

/**
 * Stands in for a queued message that can no longer be read, for example one
 * written before ids became UUIDs. It carries the stored body, so the message
 * can still be looked at in the failed transport.
 */
final readonly class UndecodableMessage
{
    /**
     * @param array<string, mixed> $encoded the envelope as the transport stored it
     */
    public function __construct(
        public array $encoded,
        public string $reason,
    ) {
    }
}
