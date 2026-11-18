<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Dashboard;

use Foresi\Ui\Dashboard\DashboardSection;
use Foresi\Ui\Dashboard\DashboardSectionProvider;
use Foresi\Work\Application\TaskGroup;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Infrastructure\Controller\MyTasksController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The tasks of the signed-in person on the start page, counted by when they are due, each count
 * opening My tasks (Asana Home and ClickUp Home both open on the person's due work). Nothing when
 * nothing is due within a week, so the page keeps its empty state.
 */
final readonly class MyTasksDashboard implements DashboardSectionProvider
{
    private const array SHOWN = [TaskGroup::OVERDUE, TaskGroup::TODAY, TaskGroup::UPCOMING];

    public function __construct(
        private WorkQueries $queries,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    public function sections(): iterable
    {
        $counts = array_fill_keys(self::SHOWN, 0);
        foreach ($this->queries->myTasks() as $group) {
            if (isset($counts[$group->key])) {
                $counts[$group->key] = \count($group->tasks);
            }
        }
        if (0 === array_sum($counts)) {
            return [];
        }
        $href = $this->urls->generate(MyTasksController::ROUTE);

        return [new DashboardSection('work/dashboard.html.twig', [
            'kpis' => array_map(fn (string $key): array => [
                'label' => $this->translator->trans('work.my_tasks.group.'.$key),
                'value' => (string) $counts[$key],
                'href' => $href,
            ], self::SHOWN),
        ], weight: 10)];
    }
}
