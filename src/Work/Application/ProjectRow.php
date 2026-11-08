<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Work\Domain\WorkflowTemplate;

/**
 * A project in the index: what it is, for whom, and how much is left of it.
 */
final readonly class ProjectRow
{
    /**
     * @param int                     $openTasks  tasks, subtasks included, whose status is not finished
     * @param \DateTimeImmutable|null $nearestDue the earliest due date of those tasks
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
    ) {
    }
}
