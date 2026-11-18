<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Instance\Application\Schedule;

/**
 * Runs one job for one period.
 */
final readonly class RunScheduledJob
{
    public function __construct(
        public string $job,
        public string $period,
    ) {
    }
}
