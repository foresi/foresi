<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Controller;

use Foresi\Identity\Application\OpenToGuest;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Ui\Component\DataTable;
use Foresi\Work\Application\TaskGroup;
use Foresi\Work\Application\TaskRow;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Domain\Priority;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * My unfinished tasks across the active projects I see, a guest's too: those I am
 * responsible for, or those I watch, by when they are due, as the My Tasks of
 * Asana. A group with nothing in it is left out; the client filter narrows
 * both tabs to the projects of one client. A project known only by its name,
 * for a task shared on its own, is not a link.
 */
#[OpenToGuest]
final class MyTasksController extends AbstractController
{
    public const string ROUTE = 'app_my_tasks';
    private const string WATCHING = 'watching';
    private const array STATUS_TONES = ['open' => 'gray', 'active' => 'amber', 'done' => 'green', 'closed' => 'gray'];

    public function __construct(
        private readonly WorkQueries $queries,
        private readonly InstanceClock $clock,
        private readonly TranslatorInterface $translator,
        private readonly InlineTaskEdits $edits,
    ) {
    }

    #[Route('/my-tasks', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $watching = self::WATCHING === $request->query->getString('tab');
        $clients = $this->clients();
        $client = $request->query->getString('client');
        $client = isset($clients[$client]) ? $client : '';
        $clientId = '' === $client ? null : $client;
        $today = $this->clock->today();
        $groups = $watching ? TaskGroup::split($this->queries->watchedTasks($clientId), $today) : $this->queries->myTasks($clientId);
        $edits = $this->edits->for(array_merge(...array_map(static fn (TaskGroup $group): array => $group->tasks, $groups)), $request->getRequestUri());
        $url = $this->generateUrl(self::ROUTE);
        $filter = '' === $client ? [] : ['client' => $client];

        return $this->render('@Work/my_tasks/index.html.twig', [
            'watching' => $watching,
            'tabs' => [
                ['label' => $this->translator->trans('work.my_tasks.tab.assigned'), 'href' => $url.$this->query($filter), 'active' => !$watching],
                ['label' => $this->translator->trans('work.my_tasks.tab.watching'), 'href' => $url.$this->query(['tab' => self::WATCHING] + $filter), 'active' => $watching],
            ],
            'client' => $client,
            'clients' => ['' => $this->translator->trans('work.my_tasks.client_any')] + $clients,
            'columns' => [
                ['key' => 'title', 'label' => $this->translator->trans('work.task.field.title'), 'truncate' => true],
                ['key' => 'project', 'label' => $this->translator->trans('work.task.field.project'), 'truncate' => true, 'phone' => false],
                ['key' => 'status', 'label' => $this->translator->trans('work.task.field.status')],
                ['key' => 'responsible', 'label' => $this->translator->trans('work.task.field.responsible'), 'truncate' => true, 'phone' => false],
                ['key' => 'due', 'label' => $this->translator->trans('work.task.field.due_on'), 'phone' => false],
                ['key' => 'priority', 'label' => $this->translator->trans('work.task.field.priority'), 'phone' => false],
            ],
            'groups' => array_values(array_map(fn (TaskGroup $group): array => [
                'key' => $group->key,
                'name' => $this->translator->trans('work.my_tasks.group.'.$group->key),
                'count' => \count($group->tasks),
                'rows' => array_map(fn (TaskRow $task): array => $this->row($task, $today, $edits), $group->tasks),
            ], array_filter($groups, static fn (TaskGroup $group): bool => [] !== $group->tasks))),
        ]);
    }

    /**
     * The clients of the active projects, by name.
     *
     * @return array<string, string> names by contact id
     */
    private function clients(): array
    {
        $clients = [];
        foreach ($this->queries->projects() as $project) {
            if (null !== $project->clientId) {
                $clients[$project->clientId] = $project->clientName ?? '';
            }
        }
        asort($clients, \SORT_NATURAL | \SORT_FLAG_CASE);

        return $clients;
    }

    /**
     * @param array<string, string> $query
     */
    private function query(array $query): string
    {
        return [] === $query ? '' : '?'.http_build_query($query);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(TaskRow $task, \DateTimeImmutable $today, TaskEdits $edits): array
    {
        $due = null;
        if ($task->dueOn instanceof \DateTimeImmutable) {
            $date = $task->dueOn->format($this->translator->trans('format.date'));
            $due = $task->dueOn->format('Y-m-d') < $today->format('Y-m-d')
                ? ['text' => $this->translator->trans('work.list.overdue', ['date' => $date]), 'tone' => 'red']
                : $date;
        }

        return [
            'id' => $task->id,
            'href' => $this->generateUrl(TaskController::ROUTE, ['id' => $task->id]),
            'opens' => DataTable::OPENS_SHEET,
            'label' => $task->title,
            'secondary' => $task->projectName,
            'cells' => [
                'title' => $task->title,
                'project' => $task->projectSeen ? ['links' => [['text' => $task->projectName, 'href' => $this->generateUrl(ProjectController::PROJECT_ROUTE, ['id' => $task->projectId])]]] : $task->projectName,
                'status' => TaskEdits::cell(['text' => $task->statusName, 'tone' => self::STATUS_TONES[$task->statusType->value]], $edits->status($task)),
                'responsible' => TaskEdits::cell(null === $task->responsibleId || null === $task->responsibleName ? null : ['avatar' => $edits->seed($task->responsibleId), 'text' => $task->responsibleName], $edits->responsible($task)),
                'due' => TaskEdits::cell($due, $edits->due($task)),
                'priority' => TaskEdits::cell(Priority::None === $task->priority ? null : $this->translator->trans('work.priority.'.$task->priority->value), $edits->priority($task)),
            ],
        ];
    }
}
