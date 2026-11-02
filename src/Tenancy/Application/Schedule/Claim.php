<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Schedule;

/**
 * The right of one worker to run a job for one period in the active tenant.
 */
final readonly class Claim
{
    public function __construct(
        public string $job,
        public string $period,
        public ?string $lastPeriod,
    ) {
    }
}
