<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Slot;

use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Foresi\Work\Application\BudgetChangeRow;
use Foresi\Work\Application\ProjectBudget;
use Foresi\Work\Application\ProjectBudgetReads;
use Foresi\Work\Application\ProjectRow;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Application\WorkVisibility;
use Foresi\Work\Contract\ProjectSlots;
use Foresi\Work\Infrastructure\Controller\ProjectBudgetController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The time budget on a project's page: "Budget 8 h · used 2.5 h · left 5.5 h"
 * under the header for whoever sees the project, over budget in the warning
 * style and never blocking (Harvest), and the Budget tab with the change log
 * for the people of the organisation, whose notes a guest never sees.
 */
final readonly class ProjectBudgetFacts implements SlotProvider
{
    public const string KEY = 'budget';
    public const string FACT_TEMPLATE = '@Work/projects/_budget_fact.html.twig';
    public const string SECTION_TEMPLATE = '@Work/projects/_budget_section.html.twig';

    /** After the deal of the project. */
    private const int FACT_WEIGHT = 20;

    /** After the activities. */
    private const int SECTION_WEIGHT = 20;

    public function __construct(
        private ProjectBudgetReads $budgets,
        private WorkQueries $queries,
        private WorkVisibility $visibility,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    public function items(string $slot, array $context): iterable
    {
        $projectId = $context['projectId'] ?? null;
        if (!\is_string($projectId) || !\in_array($slot, [ProjectSlots::HEADER_FACTS, ProjectSlots::SECTIONS], true)) {
            return [];
        }
        if (ProjectSlots::SECTIONS === $slot) {
            return $this->section($projectId);
        }
        $budget = $this->budgets->find($projectId);
        if (!$budget instanceof ProjectBudget || !$budget->hasBudget()) {
            return [];
        }

        return [new SlotItem(self::FACT_TEMPLATE, ['budget' => $this->hours($budget)], self::FACT_WEIGHT)];
    }

    /**
     * @return list<SlotItem>
     */
    private function section(string $projectId): array
    {
        if (!$this->visibility->canSeeInternal()) {
            return [];
        }
        $budget = $this->budgets->find($projectId);
        $project = $this->queries->project($projectId)?->row;
        if (!$budget instanceof ProjectBudget || !$project instanceof ProjectRow) {
            return [];
        }
        $editable = $this->visibility->canManage() && !$project->archived;

        return [new SlotItem(self::SECTION_TEMPLATE, [
            'key' => self::KEY,
            'label' => $this->translator->trans('work.project.tab.budget'),
            'budget' => $this->hours($budget),
            'editable' => $editable,
            'addHref' => $this->urls->generate(ProjectBudgetController::NEW_ROUTE, ['id' => $projectId]),
            'countingHref' => $this->urls->generate(ProjectBudgetController::COUNTING_ROUTE, ['id' => $projectId]),
            'columns' => [
                ['key' => 'effectiveOn', 'label' => $this->translator->trans('work.budget.column.effective_on')],
                ['key' => 'hours', 'label' => $this->translator->trans('work.budget.column.hours'), 'align' => 'right'],
                ['key' => 'note', 'label' => $this->translator->trans('work.budget.column.note'), 'truncate' => true],
                ['key' => 'source', 'label' => $this->translator->trans('work.budget.column.source'), 'phone' => false],
                ['key' => 'author', 'label' => $this->translator->trans('work.budget.column.author'), 'truncate' => true, 'phone' => false],
            ],
            'rows' => array_map(fn (BudgetChangeRow $change): array => $this->row($projectId, $change, $editable), $budget->changes ?? []),
        ], self::SECTION_WEIGHT)];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $projectId, BudgetChangeRow $change, bool $editable): array
    {
        $hours = $this->translator->trans('work.budget.signed_hours', ['hours' => round($change->minutes / 60, 2)]);

        return [
            'id' => $change->id,
            'label' => $hours,
            'cells' => [
                'effectiveOn' => $change->effectiveOn->format($this->translator->trans('format.date')),
                'hours' => ['text' => $hours, 'numeric' => true],
                'note' => $change->note,
                'source' => null === $change->sourceActivityId ? '' : $this->translator->trans('work.budget.source_activity'),
                'author' => $change->authorName ?? '',
            ],
            'actions' => $editable ? [[
                'label' => $this->translator->trans('work.budget.remove'),
                'href' => $this->urls->generate(ProjectBudgetController::REMOVE_ROUTE, ['id' => $projectId, 'changeId' => $change->id]),
                'method' => 'POST',
                'icon' => 'trash-2',
                'confirm' => [
                    'title' => $this->translator->trans('work.budget.remove_confirm.title', ['hours' => $hours]),
                    'text' => $this->translator->trans('work.budget.remove_confirm.text'),
                    'accept' => $this->translator->trans('work.budget.remove'),
                    'cancel' => $this->translator->trans('work.cancel'),
                    'tone' => 'danger',
                ],
            ]] : [],
        ];
    }

    /**
     * @return array{budget: float|null, used: float, left: float|null, estimate: float, over: bool, counting: string}
     */
    private function hours(ProjectBudget $budget): array
    {
        $hours = static fn (?int $minutes): ?float => null === $minutes ? null : round($minutes / 60, 2);

        return [
            'budget' => $hours($budget->budgetMinutes),
            'used' => (float) $hours($budget->usedMinutes),
            'left' => $hours($budget->leftMinutes()),
            'estimate' => (float) $hours($budget->estimateMinutes),
            'over' => $budget->isOver(),
            'counting' => $budget->counting->value,
        ];
    }
}
