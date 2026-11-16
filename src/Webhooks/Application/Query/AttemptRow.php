<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application\Query;

/**
 * One request made for a delivery: when, how long it took, the status the
 * receiver answered with or a short error, and what asked for it (schedule,
 * manual or ping). An attempt that is queued or was cut short has no status
 * and no error yet.
 */
final readonly class AttemptRow
{
    public function __construct(
        public string $id,
        public \DateTimeImmutable $attemptedAt,
        public int $durationMs,
        public ?int $statusCode,
        public ?string $error,
        public string $trigger,
    ) {
    }
}
