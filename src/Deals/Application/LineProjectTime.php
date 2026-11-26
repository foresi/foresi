<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Port: the time logged on the project an hourly line links to, answered by
 * Sales' integration with Timesheet (Integration/Timesheet) when time is
 * tracked. Without it a line shows no time logged.
 */
#[AutoconfigureTag(self::TAG)]
interface LineProjectTime
{
    public const string TAG = 'foresi.sales.line_project_time';

    /** The billable minutes logged on the project that the person sees, the trash left out. */
    public function billableMinutes(string $projectId): int;
}
