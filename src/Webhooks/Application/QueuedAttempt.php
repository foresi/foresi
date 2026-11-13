<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application;

/**
 * An attempt that is queued and not yet made: the delivery it is of, and the
 * id its attempt is recorded under once it is.
 */
final readonly class QueuedAttempt
{
    public function __construct(public string $deliveryId, public string $attemptId)
    {
    }
}
