<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Directory;

use Foresi\Shared\Domain\Id;
use Foresi\Work\Application\TimeGrouping;
use Foresi\Work\Application\TimeQueries;
use Foresi\Work\Application\TimeReportFilter;
use Foresi\Work\Contract\TimeTotals;

/**
 * The contract over the time report (TimeQueries::report), which already
 * leaves out the trash and running timers and keeps to what the person sees.
 */
final readonly class WorkTimeTotals implements TimeTotals
{
    public function __construct(private TimeQueries $time)
    {
    }

    public function minutes(string $projectId, ?\DateTimeImmutable $from = null, bool $billableOnly = false): int
    {
        if (!Id::isValid($projectId)) {
            return 0;
        }
        $report = $this->time->report(new TimeReportFilter($from, null, $projectId), TimeGrouping::Project);

        return $billableOnly ? $report->billableMinutes : $report->totalMinutes;
    }
}
