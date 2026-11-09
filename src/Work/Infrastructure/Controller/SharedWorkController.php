<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Controller;

use App\Identity\Application\OpenToGuest;
use App\Identity\Application\StaffCheck;
use App\Identity\Infrastructure\Controller\DashboardController;
use App\Tenancy\Application\TenantClock;
use App\Work\Application\SharedProject;
use App\Work\Application\SharedTaskGroup;
use App\Work\Application\TaskRow;
use App\Work\Application\WorkQueries;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The start page of a guest, as the client home of Basecamp: the projects
 * shared with them first, all of each or only its sections, then the tasks
 * shared with them on their own, under the name of their project, which is
 * not a link when they do not see it. The staff have the projects instead.
 */
#[OpenToGuest]
final class SharedWorkController extends AbstractController
{
    public const string ROUTE = DashboardController::GUEST_HOME;
    private const array STATUS_TONES = ['open' => 'gray', 'active' => 'amber', 'done' => 'green', 'closed' => 'gray'];

    public function __construct(
        private readonly WorkQueries $queries,
        private readonly StaffCheck $staff,
        private readonly TenantClock $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/shared', name: self::ROUTE, methods: ['GET'])]
    public function __invoke(): Response
    {
        if ($this->staff->isStaff()) {
            return $this->redirectToRoute(ProjectController::ROUTE);
        }
        $shared = $this->queries->sharedWithMe();

        return $this->render('work/shared/index.html.twig', [
            'empty' => $shared->isEmpty(),
            'projectColumns' => [
                ['key' => 'name', 'label' => $this->translator->trans('work.projects.column.name'), 'truncate' => true],
                ['key' => 'shared', 'label' => $this->translator->trans('shared_with_me.column.shared'), 'truncate' => true, 'phone' => false],
                ['key' => 'open', 'label' => $this->translator->trans('work.projects.column.open'), 'align' => 'right'],
                ['key' => 'due', 'label' => $this->translator->trans('work.projects.column.due'), 'align' => 'right', 'phone' => false],
            ],
            'projects' => array_map($this->projectRow(...), $shared->projects),
            'taskColumns' => [
                ['key' => 'title', 'label' => $this->translator->trans('work.task.field.title'), 'truncate' => true],
                ['key' => 'status', 'label' => $this->translator->trans('work.task.field.status')],
                ['key' => 'due', 'label' => $this->translator->trans('work.task.field.due_on'), 'phone' => false],
            ],
            'groups' => array_map(fn (SharedTaskGroup $group, int $at): array => [
                'key' => (string) $at,
                'name' => $group->projectName,
                'rows' => array_map($this->taskRow(...), $group->tasks),
            ], $shared->tasks, array_keys($shared->tasks)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function projectRow(SharedProject $shared): array
    {
        $project = $shared->project;
        $sections = implode(', ', $shared->sections);

        return [
            'id' => $project->id,
            'href' => $this->generateUrl(ProjectController::PROJECT_ROUTE, ['id' => $project->id]),
            'label' => $project->name,
            'secondary' => [] === $shared->sections ? null : $sections,
            'cells' => [
                'name' => $project->name,
                'shared' => [] === $shared->sections ? $this->translator->trans('shared_with_me.whole_project') : $sections,
                'open' => (string) $project->openTasks,
                'due' => $project->nearestDue instanceof \DateTimeImmutable ? $this->date($project->nearestDue) : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(TaskRow $task): array
    {
        $due = null;
        if ($task->dueOn instanceof \DateTimeImmutable) {
            $date = $this->date($task->dueOn);
            $due = !$task->done && $task->dueOn->format('Y-m-d') < $this->clock->today()->format('Y-m-d')
                ? ['text' => $this->translator->trans('work.list.overdue', ['date' => $date]), 'tone' => 'red']
                : $date;
        }

        return [
            'id' => $task->id,
            'href' => $this->generateUrl(TaskController::ROUTE, ['id' => $task->id]),
            'label' => $task->title,
            // A phone hides the column of the date: the line under the title says it, and lets a long title wrap.
            'secondary' => \is_array($due) ? $due['text'] : ($due ?? $this->translator->trans('work.my_tasks.group.no_date')),
            'cells' => [
                'title' => $task->title,
                'status' => ['text' => $task->statusName, 'tone' => self::STATUS_TONES[$task->statusType->value]],
                'due' => $due,
            ],
        ];
    }

    private function date(\DateTimeImmutable $date): string
    {
        return $date->format($this->translator->trans('format.date'));
    }
}
