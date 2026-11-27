<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Work\Domain\ProjectVisibility;
use Foresi\Work\Domain\WorkflowTemplate;

/**
 * A project in the index: what it is, for whom, and how much is left of it.
 */
final readonly class ProjectRow
{
    /**
     * @param int                     $openTasks       tasks, subtasks included, whose status is not finished
     * @param \DateTimeImmutable|null $nearestDue      the earliest due date of those tasks
     * @param int                     $overdueTasks    those of them past their due date
     * @param int                     $unassignedTasks those of them with no one responsible
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $clientId,
        public ?string $clientName,
        public WorkflowTemplate $template,
        public int $openTasks,
        public ?\DateTimeImmutable $nearestDue,
        public bool $archived,
        public ProjectVisibility $visibility,
        public int $overdueTasks,
        public int $unassignedTasks,
    ) {
    }
}
