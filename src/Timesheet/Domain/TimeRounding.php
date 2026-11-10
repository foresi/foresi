<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Domain;

/**
 * How each entry of time is rounded before the entries of a line are summed:
 * not at all, or up to the next quarter or half hour (Toggl, Harvest). Only
 * what is billed is rounded; the entry keeps the minutes worked.
 */
enum TimeRounding: string
{
    case None = 'none';
    case UpToQuarterHour = '15';
    case UpToHalfHour = '30';

    public function minutes(int $worked): int
    {
        $step = match ($this) {
            self::None => 1,
            self::UpToQuarterHour => 15,
            self::UpToHalfHour => 30,
        };

        return intdiv($worked + $step - 1, $step) * $step;
    }
}
