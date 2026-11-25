<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Work\Domain\BudgetCounting;

/**
 * A project's time budget as it stands, in minutes: the budget (null without
 * any change), the time that used it, what is left (negative over budget) and
 * the sum of the task estimates next to it. The changes are for the people of
 * the organisation only (null for a guest): their notes and sources may hold
 * commercial terms.
 */
final readonly class ProjectBudget
{
    /**
     * @param list<BudgetChangeRow>|null $changes
     */
    public function __construct(
        public string $projectId,
        public ?int $budgetMinutes,
        public int $usedMinutes,
        public BudgetCounting $counting,
        public int $estimateMinutes,
        public ?array $changes,
    ) {
    }

    public function hasBudget(): bool
    {
        return null !== $this->budgetMinutes;
    }

    public function leftMinutes(): ?int
    {
        return null === $this->budgetMinutes ? null : $this->budgetMinutes - $this->usedMinutes;
    }

    public function isOver(): bool
    {
        return null !== $this->budgetMinutes && $this->usedMinutes > $this->budgetMinutes;
    }
}
