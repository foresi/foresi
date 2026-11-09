<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Work\Domain\Priority;
use App\Work\Domain\StatusType;

/**
 * A task as a line of the list, a card of the board or a row of my tasks.
 */
final readonly class TaskRow
{
    /**
     * @param list<LabelBadge> $labels    by name
     * @param list<TaskRow>    $subtasks  by position; empty where a view does not show them
     * @param bool             $done      whether its status is finished
     * @param bool             $deletable whether the signed-in person may put it in the trash
     * @param bool             $private   whether only the people it is shared with see it
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $projectName,
        public ?string $sectionId,
        public ?string $parentId,
        public string $title,
        public string $statusId,
        public string $statusName,
        public StatusType $statusType,
        public ?string $responsibleId,
        public ?string $responsibleName,
        public ?\DateTimeImmutable $dueOn,
        public Priority $priority,
        public array $labels,
        public int $subtaskCount,
        public bool $done,
        public array $subtasks = [],
        public bool $deletable = false,
        public bool $private = false,
    ) {
    }
}
