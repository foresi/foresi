<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Integration\Timesheet;

use Foresi\Deals\Application\LineProjectTime;
use Foresi\Timesheet\Contract\TimeTotals;

/**
 * The billable time logged on the project of an hourly line, read from
 * Timesheet's contract as the person signed in sees it.
 */
final readonly class TimesheetLineProjectTime implements LineProjectTime
{
    public function __construct(private TimeTotals $time)
    {
    }

    public function billableMinutes(string $projectId): int
    {
        return $this->time->minutes($projectId, billableOnly: true);
    }
}
