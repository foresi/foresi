<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Column;

use Foresi\Work\Application\View\RecurringTaskLists;
use Foresi\Work\Domain\RecurringTaskState;

/**
 * A recurring task as a row of its list.
 */
final readonly class RecurringTaskRow
{
    public function __construct(
        public string $id,
        public string $title,
        public string $projectId,
        public string $projectName,
        public RecurringTaskState $state,
        public string $schedule,
        public ?\DateTimeImmutable $next,
        public int $created,
        public bool $failing,
    ) {
    }

    /**
     * The value of a field of the list, as its conditions compare it.
     */
    public function value(string $field): mixed
    {
        return match ($field) {
            RecurringTaskLists::TITLE => $this->title,
            RecurringTaskLists::PROJECT => $this->projectName,
            RecurringTaskLists::STATE => $this->state->value,
            RecurringTaskLists::NEXT => $this->next,
            default => null,
        };
    }
}
