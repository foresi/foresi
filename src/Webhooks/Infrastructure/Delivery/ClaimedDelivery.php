<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Webhooks\Infrastructure\Delivery;

/**
 * A delivery as it was when a worker claimed it: what to send where, and how
 * many attempts were made before this one.
 */
final readonly class ClaimedDelivery
{
    public function __construct(
        public string $id,
        public string $endpointId,
        public string $eventId,
        public string $body,
        public string $status,
        public int $attempts,
        public string $kind,
    ) {
    }
}
