<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Work\Domain\StatusType;

/**
 * What a slice of the tasks is narrowed to, as GET /api/v1/tasks and its tool
 * ask for it. Every value narrows; none lists every task the person sees in
 * the active projects.
 */
final readonly class TaskSliceFilter
{
    /** The responsible of the tasks is the signed-in person. */
    public const string ME = 'me';

    /**
     * @param string|null     $responsible ME, a membership id, or null for anyone and no one
     * @param bool|null       $finished    true for tasks in a finished status, false for the others, null for both
     * @param ViewFilter|null $view        the conditions of a saved view and of a filter, as fields of the list work.tasks (TaskLists)
     * @param bool            $open        whether a finished task is left out unless a condition of the view asks about the status type, as the list work.tasks does
     * @param string          $sort        one of TaskLists::SORTS, a minus first for backwards; empty for the newest first
     */
    public function __construct(
        public string $text = '',
        public ?string $projectId = null,
        public ?string $responsible = null,
        public ?StatusType $statusType = null,
        public ?bool $finished = null,
        public ?DueBucket $due = null,
        public ?ViewFilter $view = null,
        public bool $open = false,
        public string $sort = '',
    ) {
    }
}
