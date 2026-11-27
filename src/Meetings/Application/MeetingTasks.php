<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application;

use Foresi\Work\Contract\TaskSummary;

/**
 * The tasks of a meeting's page as the person sees them, read in one go: every
 * task its minutes refer to, its action items, and the open ones carried over
 * from earlier meetings of its series.
 */
final readonly class MeetingTasks
{
    /**
     * @param array<string, TaskSummary> $summaries   every task of the page the person sees, by id, those in the trash flagged
     * @param list<TaskSummary>          $actionItems the tasks made from the meeting, in the order made
     * @param list<CarriedOver>          $carryOver   the open tasks of earlier meetings by the horizon rule, by meeting, newest first
     * @param int                        $seriesOpen  the open tasks of all the meetings of the series
     */
    public function __construct(
        public array $summaries,
        public array $actionItems,
        public array $carryOver,
        public int $seriesOpen,
    ) {
    }
}
