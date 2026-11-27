<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

/**
 * Every field of a recurring task as a create or an update gives it, by id and
 * as text, before ManageRecurringTasks checks it.
 */
final readonly class RecurringTaskFields
{
    /**
     * @param list<string>         $labelIds
     * @param list<string>         $watcherIds
     * @param list<string>         $subtasks
     * @param array<string, mixed> $customFields as JSON keeps them
     */
    public function __construct(
        public string $title,
        public string $description,
        public ?string $sectionId,
        public ?string $responsibleId,
        public string $priority,
        public bool $private,
        public ?int $estimateMinutes,
        public array $labelIds,
        public array $watcherIds,
        public array $subtasks,
        public string $firstOn,
        public string $repeat,
        public int $every,
        public bool $lastDayOfMonth,
        public string $end,
        public ?string $endsOn,
        public ?int $endsAfter,
        public int $createDaysBefore,
        public ?int $dueDaysAfter,
        public array $customFields,
    ) {
    }
}
