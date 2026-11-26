<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Application\Api;

use Foresi\Timesheet\Application\BudgetHours;
use Foresi\Timesheet\Application\ProjectBudgets;
use Foresi\Timesheet\Application\TimeRefused;
use Foresi\Timesheet\Domain\BudgetCounting;
use Foresi\Work\Application\WorkRefused;

/**
 * The writes of a project's time budget over the API and MCP (ADR 0032),
 * running the use cases the Budget tab runs.
 */
final readonly class BudgetWrites
{
    public function __construct(private ProjectBudgets $budgets)
    {
    }

    /**
     * @return string the id of the change
     *
     * @throws TimeRefused|WorkRefused
     */
    public function add(AddProjectBudgetChangeInput $input): string
    {
        $minutes = match (true) {
            null !== $input->hours && null === $input->minutes => BudgetHours::minutes($input->hours),
            null === $input->hours && null !== $input->minutes => $input->minutes,
            default => null,
        } ?? throw new TimeRefused('hours_invalid');

        return $this->budgets->add($input->projectId, $minutes, $this->day($input->effectiveOn), $input->note, $input->sourceActivityId)->id();
    }

    /**
     * @throws TimeRefused|WorkRefused
     */
    public function remove(RemoveProjectBudgetChangeInput $input): void
    {
        $this->budgets->remove($input->projectId, $input->changeId);
    }

    /**
     * @throws TimeRefused|WorkRefused
     */
    public function count(SetProjectBudgetCountingInput $input): void
    {
        $this->budgets->count($input->projectId, BudgetCounting::from($input->counting));
    }

    private function day(?string $value): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$day instanceof \DateTimeImmutable || $day->format('Y-m-d') !== $value || $day < new \DateTimeImmutable('1900-01-01')) {
            throw new TimeRefused('date', 'effectiveOn');
        }

        return $day;
    }
}
