<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Infrastructure\Api\Operation;

use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Timesheet\Application\ProjectBudgetReads;
use Foresi\Timesheet\Application\TimeQueries;
use Foresi\Timesheet\Application\TimeRefused;
use Foresi\Timesheet\Infrastructure\Api\TimesheetJson;

/**
 * What the writes of time answer: what the read endpoints and tools answer for
 * the time entry or the project's time budget as it is now.
 */
final readonly class TimesheetAnswers
{
    public function __construct(private TimeQueries $time, private ProjectBudgetReads $budgets)
    {
    }

    public function timeEntry(string $id, string $summary): OperationOutput
    {
        $entry = $this->time->entry($id) ?? throw new TimeRefused('unknown');

        return new OperationOutput(TimesheetJson::timeEntry($entry), $summary);
    }

    /**
     * The time budget of the project as it is now.
     *
     * @param string|null $created the address of the change created, for REST
     */
    public function budget(string $projectId, string $summary, ?string $created = null): OperationOutput
    {
        return new OperationOutput(TimesheetJson::budget($this->budgets->budget($projectId)), $summary, $created);
    }
}
