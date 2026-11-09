<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * The task page: the task with everything around it and what its fields offer.
 */
final readonly class TaskDetail
{
    /**
     * @param list<Choice>       $watchers    by name, membership ids
     * @param list<StatusChoice> $statuses    of the project, in order
     * @param list<Choice>       $sections    of the project, in order
     * @param list<Choice>       $people      active people of the organisation who can be responsible or watch
     * @param bool               $shareable   whether the signed-in person may share the task or make it private
     * @param bool               $manageable  whether the signed-in person works for the organisation: the internal side of the task is theirs
     * @param bool               $projectOpen whether the person sees all of the project that is not private, and so may put the task in no section
     */
    public function __construct(
        public TaskRow $task,
        public string $description,
        public ?Choice $parent,
        public ?string $sectionName,
        public array $watchers,
        public array $statuses,
        public array $sections,
        public array $people,
        public bool $projectArchived,
        public \DateTimeImmutable $createdAt,
        public ?string $createdByName,
        public ?\DateTimeImmutable $completedAt,
        public bool $shareable = false,
        public bool $manageable = false,
        public bool $projectOpen = false,
    ) {
    }
}
