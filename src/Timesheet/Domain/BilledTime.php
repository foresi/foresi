<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Domain;

/**
 * An entry on its line, with the minutes billed after rounding.
 */
final readonly class BilledTime
{
    public function __construct(
        public BillableTime $time,
        public int $minutes,
    ) {
    }
}
