<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Contract;

use Foresi\Shared\Domain\Money;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Port: an hourly rate a module sets for the time of a whole project, asked
 * before the project's own rate. Sales answers it for the project an hourly
 * deal line links to. Timesheet knows nothing of who answers; the first rate
 * given wins. The rate is read when time is invoiced and then stored with it,
 * so a later change of the source never reaches billed time.
 */
#[AutoconfigureTag(self::TAG)]
interface ProjectRateSource
{
    public const string TAG = 'foresi.timesheet.project_rate_source';

    /**
     * @return Money|null the rate per hour; null when this source sets none for the project
     */
    public function rateFor(string $projectId): ?Money;
}
