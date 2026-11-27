<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Domain;

use Foresi\Identity\Domain\Membership;
use Foresi\Shared\Domain\Schedule\Recurrence;

/**
 * What every task of a recurring task gets, and when: the fields copied to each
 * occurrence, the subtasks it starts with, the schedule, how many days before
 * its date it is created and when it is due. The texts may hold period
 * variables (PeriodText). A due offset of null means the end of the period, so
 * a weekly task created on Monday is due on Sunday.
 */
final readonly class RecurringTaskTemplate
{
    /**
     * @param list<string>         $labelIds     the labels, by id
     * @param list<string>         $watcherIds   the people who follow each task, by membership id
     * @param array<string, mixed> $customFields the custom fields by key, as Fields::write() takes them
     * @param list<string>         $subtasks     the titles of the subtasks each task starts with, in order
     */
    public function __construct(
        public string $title,
        public string $description,
        public ?Section $section,
        public ?Membership $responsible,
        public Priority $priority,
        public bool $private,
        public ?int $estimateMinutes,
        public array $labelIds,
        public array $watcherIds,
        public array $customFields,
        public array $subtasks,
        public Recurrence $recurrence,
        public int $createDaysBefore = 0,
        public ?int $dueDaysAfter = null,
    ) {
    }
}
