<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Controller;

use App\Audit\Application\AuditActor;
use App\Identity\Application\People;
use App\Identity\Application\Person;
use App\Identity\Application\StaffCheck;
use App\Party\Application\PartyQuery;
use App\Party\Application\PartyRow;
use App\Tenancy\Application\TenantClock;
use App\Ui\Avatar;
use App\Work\Application\DueBucket;
use App\Work\Application\LabelBadge;
use App\Work\Application\ManageProjects;
use App\Work\Application\ManageTasks;
use App\Work\Application\ProjectRow;
use App\Work\Application\PurgeDeletedTasks;
use App\Work\Application\SectionTasks;
use App\Work\Application\StatusTasks;
use App\Work\Application\TaskFilter;
use App\Work\Application\TaskRow;
use App\Work\Application\TrashedTask;
use App\Work\Application\WorkQueries;
use App\Work\Application\WorkRefused;
use App\Work\Domain\Priority;
use App\Work\Domain\StatusType;
use App\Work\Domain\WorkflowTemplate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The projects of the organisation, for its staff: the index of active and
 * archived projects, a new project in a dialog over it (the same URL is a page
 * of its own), and the list of a project, section by section, with a line at
 * the end of each section that adds a task by its title. The board shows the
 * same tasks as one column per status; a card moves by dragging it or by the
 * move items of its menu, which work as plain forms. The trash of a project
 * lists the tasks deleted from it, each restored by a button.
 */
final class ProjectController extends AbstractController
{
    public const string ROUTE = 'app_projects';
    public const string PROJECT_ROUTE = 'app_project';
    public const string BOARD_ROUTE = 'app_project_board';
    public const string TRASH_ROUTE = 'app_project_trash';
    /** The menu forms of a card and the drop on the board share it. */
    public const string MOVE_TOKEN = 'work_task_move';
    public const string TOKEN = 'work_project';
    public const string TASK_TOKEN = 'work_task_add';
    /** The add-task line of the tasks in no section. */
    public const string NO_SECTION = 'none';
    private const int MAX_QUERY = 100;
    private const string ARCHIVED = 'archived';
    private const array STATUS_FILTERS = ['open', 'all', 'finished'];
    private const array STATUS_TONES = ['open' => 'gray', 'active' => 'amber', 'done' => 'green', 'closed' => 'gray'];

    public function __construct(
        private readonly WorkQueries $queries,
        private readonly ManageProjects $projects,
        private readonly ManageTasks $tasks,
        private readonly PartyQuery $parties,
        private readonly People $people,
        private readonly StaffCheck $staff,
        private readonly AuditActor $actor,
        private readonly TenantClock $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/projects', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessStaff();
        $archived = self::ARCHIVED === $request->query->getString('tab');
        $url = $this->generateUrl(self::ROUTE);

        return $this->render('work/projects/index.html.twig', [
            'archived' => $archived,
            'tabs' => [
                ['label' => $this->translator->trans('work.projects.tab.active'), 'href' => $url, 'active' => !$archived],
                ['label' => $this->translator->trans('work.projects.tab.archived'), 'href' => $url.'?tab='.self::ARCHIVED, 'active' => $archived],
            ],
            'columns' => [
                ['key' => 'name', 'label' => $this->translator->trans('work.projects.column.name'), 'truncate' => true],
                ['key' => 'client', 'label' => $this->translator->trans('work.projects.column.client'), 'truncate' => true, 'phone' => false],
                ['key' => 'open', 'label' => $this->translator->trans('work.projects.column.open'), 'align' => 'right'],
                ['key' => 'due', 'label' => $this->translator->trans('work.projects.column.due'), 'align' => 'right', 'phone' => false],
            ],
            'rows' => array_map($this->projectRow(...), $this->queries->projects($archived)),
        ]);
    }

    #[Route('/projects/new', name: 'app_project_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyUnlessStaff();
        $values = ['name' => '', 'clientId' => '', 'clientQuery' => '', 'workflow' => WorkflowTemplate::Simple->value, 'description' => ''];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request, self::TOKEN);
            foreach (array_keys($values) as $field) {
                $values[$field] = 'description' === $field ? $request->request->getString($field) : trim($request->request->getString($field));
            }
            $template = WorkflowTemplate::tryFrom($values['workflow']);
            if ('' === $values['name']) {
                $errors['name'] = $this->translator->trans('work.project.error.name_required');
            }
            if (!$template instanceof WorkflowTemplate) {
                $errors['workflow'] = $this->translator->trans('work.project.error.workflow');
            }
            if ([] === $errors && $template instanceof WorkflowTemplate) {
                try {
                    $project = $this->projects->create($values['name'], '' === $values['clientId'] ? null : $values['clientId'], $template, $values['description']);

                    return $this->redirectToRoute(self::PROJECT_ROUTE, ['id' => $project->id()], Response::HTTP_SEE_OTHER);
                } catch (WorkRefused $refused) {
                    $errors[match ($refused->reason) {
                        'name' => 'name',
                        'client' => 'client',
                        default => 'form',
                    }] = $this->translator->trans('work.refused.'.$refused->reason);
                }
            }
        }

        return $this->render('work/projects/new.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'workflows' => [
                ['value' => WorkflowTemplate::Simple->value, 'label' => $this->translator->trans('work.workflow.simple'), 'text' => $this->translator->trans('work.workflow.simple_text')],
                ['value' => WorkflowTemplate::Development->value, 'label' => $this->translator->trans('work.workflow.development'), 'text' => $this->translator->trans('work.workflow.development_text')],
            ],
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * The contacts the client field offers: any contact of the address book.
     */
    #[Route('/projects/clients', name: 'app_project_clients', methods: ['GET'])]
    public function clients(Request $request): JsonResponse
    {
        $this->denyUnlessStaff();
        $page = $this->parties->search(mb_substr($request->query->getString('q'), 0, self::MAX_QUERY), null, false, 'name', 1);

        return $this->json(['results' => array_map(fn (PartyRow $row): array => [
            'id' => $row->id,
            'label' => $row->displayName,
            'hint' => $row->email ?? $row->registrationNumber ?? $this->translator->trans('party.kind.'.$row->kind->value),
        ], $page->rows)]);
    }

    #[Route('/projects/{id}', name: self::PROJECT_ROUTE, requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function show(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $query = $this->filterQuery($request->query);
        $list = $this->queries->projectList($id, $this->filter($query)) ?? throw new NotFoundHttpException();
        $seeds = $this->seeds();
        $sections = [];
        foreach ($list->sections as $section) {
            // The tasks in no section only when there are some, or when the project has no section to add them to.
            if (null === $section->id && [] === $section->tasks && \count($list->sections) > 1) {
                continue;
            }
            $sections[] = $this->sectionView($section, $seeds);
        }

        return $this->render('work/projects/show.html.twig', [
            'project' => $list->project,
            'sections' => $sections,
            'query' => $query,
            'filtered' => '' !== $query['responsible'] || 'open' !== $query['status'] || '' !== $query['due'],
            'adding' => $request->query->getString('add'),
            'responsibles' => $this->responsibles(),
            'statuses' => [
                'open' => $this->translator->trans('work.filter.status_open'),
                'all' => $this->translator->trans('work.filter.status_all'),
                'finished' => $this->translator->trans('work.filter.status_finished'),
            ],
            'dues' => $this->dues(),
            'columns' => $this->taskColumns(),
            'trashCount' => $this->queries->trashCount($id),
        ]);
    }

    /**
     * The project as one column per status, in the order of the statuses, with
     * the top-level tasks as cards in their order. The status filter of the
     * list does not apply: every status has its column.
     */
    #[Route('/projects/{id}/board', name: self::BOARD_ROUTE, requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function board(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $query = $this->filterQuery($request->query);
        $board = $this->queries->board($id, $this->filter(['status' => 'all'] + $query)) ?? throw new NotFoundHttpException();
        $seeds = $this->seeds();
        $statuses = array_map(static fn (StatusTasks $column): array => ['id' => $column->id, 'name' => $column->name], $board->columns);

        return $this->render('work/projects/board.html.twig', [
            'project' => $board->project,
            'query' => ['responsible' => $query['responsible'], 'due' => $query['due']],
            'filtered' => '' !== $query['responsible'] || '' !== $query['due'],
            'responsibles' => $this->responsibles(),
            'dues' => $this->dues(),
            'columns' => array_map(fn (StatusTasks $column): array => [
                'id' => $column->id,
                'name' => $column->name,
                'tone' => self::STATUS_TONES[$column->type->value],
                'type' => $this->translator->trans('work.board.type.'.$column->type->value),
                'count' => \count($column->tasks),
                'cards' => array_map(
                    fn (TaskRow $task, int $at): array => $this->card($task, $seeds, $column->id, $statuses, $at, \count($column->tasks)),
                    $column->tasks,
                    array_keys($column->tasks),
                ),
            ], $board->columns),
        ]);
    }

    /**
     * The tasks deleted from the project, the last first: those deleted with
     * their task are restored with it, so only it is listed. Restoring is
     * offered to whom may restore each one.
     */
    #[Route('/projects/{id}/trash', name: self::TRASH_ROUTE, requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function trash(string $id): Response
    {
        $this->denyUnlessStaff();
        $trash = $this->queries->trash($id) ?? throw new NotFoundHttpException();

        return $this->render('work/projects/trash.html.twig', [
            'project' => $trash->project,
            'days' => PurgeDeletedTasks::DAYS,
            'columns' => [
                ['key' => 'title', 'label' => $this->translator->trans('work.task.field.title'), 'truncate' => true],
                ['key' => 'parent', 'label' => $this->translator->trans('work.trash.column.parent'), 'truncate' => true, 'phone' => false],
                ['key' => 'deletedBy', 'label' => $this->translator->trans('work.trash.column.deleted_by'), 'truncate' => true, 'phone' => false],
                ['key' => 'deletedAt', 'label' => $this->translator->trans('work.trash.column.deleted_at')],
            ],
            'rows' => array_map(fn (TrashedTask $task): array => [
                'id' => $task->id,
                'label' => $task->title,
                'secondary' => null === $task->parentTitle ? null : $this->translator->trans('work.trash.subtask_of', ['title' => $task->parentTitle]),
                'cells' => [
                    'title' => $task->title,
                    'parent' => $task->parentTitle,
                    'deletedBy' => $task->deletedByName,
                    'deletedAt' => $task->deletedAt->format($this->translator->trans('format.datetime')),
                ],
                'actions' => $task->restorable ? [[
                    'label' => $this->translator->trans('work.trash.restore'),
                    'href' => $this->generateUrl('app_task_restore', ['id' => $task->id]),
                    'method' => 'POST',
                    'icon' => 'archive-restore',
                    'fields' => ['from' => 'trash', 'project' => $trash->project->id],
                ]] : [],
            ], $trash->tasks),
        ]);
    }

    /**
     * Moves a task on the board. A drop sends the status and the task it lands
     * before (none for the end of the column) and gets 204, or 422 with the
     * reason; the menu of a card sends a status, or a direction within its
     * column, and comes back to the board, where a refusal is told.
     */
    #[Route('/tasks/{id}/move', name: 'app_task_move', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function move(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $this->checkToken($request, self::MOVE_TOKEN);
        $task = $this->queries->task($id) ?? throw new NotFoundHttpException();
        $query = $this->filterQuery($request->request);
        $direction = $request->request->getString('direction');
        $target = [$request->request->getString('statusId'), $request->request->getString('beforeTaskId')];
        $json = \in_array('application/json', $request->getAcceptableContentTypes(), true);
        try {
            if ('up' === $direction || 'down' === $direction) {
                $target = $this->neighbour($task->task->projectId, $id, 'up' === $direction, $query);
            }
            if (null !== $target) {
                $this->tasks->move($id, $target[0], '' === $target[1] ? null : $target[1]);
            }
        } catch (WorkRefused $refused) {
            if ('unknown' === $refused->reason) {
                throw new NotFoundHttpException($refused->getMessage(), $refused);
            }
            $message = $this->translator->trans('work.refused.'.$refused->reason);
            $this->addFlash('error', $message);
            if ($json) {
                return $this->json(['error' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }
        if ($json) {
            return new Response(status: Response::HTTP_NO_CONTENT);
        }

        return $this->redirectToRoute(self::BOARD_ROUTE, ['id' => $task->task->projectId] + array_filter(
            ['responsible' => $query['responsible'], 'due' => $query['due']],
            static fn (string $value): bool => '' !== $value,
        ), Response::HTTP_SEE_OTHER);
    }

    /**
     * Adds a task by its title to a section, or to no section, and goes back
     * to the list with the same line ready for the next one. An empty title
     * adds nothing.
     */
    #[Route('/projects/{id}/tasks', name: 'app_project_task_add', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function addTask(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->checkToken($request, self::TASK_TOKEN);
        $section = $request->request->getString('section');
        $section = '' === $section || self::NO_SECTION === $section ? null : $section;
        $title = trim($request->request->getString('title'));
        if ('' !== $title) {
            try {
                $this->tasks->create($id, $title, $section);
            } catch (WorkRefused $refused) {
                if ('unknown' === $refused->reason) {
                    throw new NotFoundHttpException($refused->getMessage(), $refused);
                }
                $this->addFlash('error', $this->translator->trans('work.refused.'.$refused->reason));
            }
        }
        $query = array_filter([
            'responsible' => $request->request->getString('responsible'),
            'status' => $request->request->getString('status'),
            'due' => $request->request->getString('due'),
        ], static fn (string $value): bool => '' !== $value);
        $key = $section ?? self::NO_SECTION;

        return $this->redirectToRoute(self::PROJECT_ROUTE, ['id' => $id] + $query + ['add' => $key, '_fragment' => 'add-'.$key], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param InputBag<string> $input the query of a page, or the fields of a form that goes back to it
     *
     * @return array{responsible: string, status: string, due: string}
     */
    private function filterQuery(InputBag $input): array
    {
        $responsible = $input->getString('responsible');
        $status = $input->getString('status', 'open');
        $due = $input->getString('due');

        return [
            'responsible' => 'me' === $responsible || 1 === preg_match('/^'.Requirement::UUID.'$/', $responsible) ? $responsible : '',
            'status' => \in_array($status, self::STATUS_FILTERS, true) ? $status : 'open',
            'due' => DueBucket::tryFrom($due) instanceof DueBucket ? $due : '',
        ];
    }

    /**
     * @param array{responsible: string, status: string, due: string} $query
     */
    private function filter(array $query): TaskFilter
    {
        $responsible = $query['responsible'];
        if ('me' === $responsible) {
            $userId = $this->actor->userId();
            // Someone without a membership is responsible for nothing.
            $responsible = (null === $userId ? null : $this->people->membershipOf($userId)?->id()) ?? 'nobody';
        }

        return new TaskFilter(
            '' === $responsible ? null : $responsible,
            null,
            DueBucket::tryFrom($query['due']),
            match ($query['status']) {
                'open' => false,
                'finished' => true,
                default => null,
            },
        );
    }

    /**
     * Where a card goes one step up or down its column as the board shows it:
     * its status and the task it then stands before, or null when it is at that end already.
     *
     * @param array{responsible: string, status: string, due: string} $query
     *
     * @return array{string, string}|null
     */
    private function neighbour(string $projectId, string $taskId, bool $up, array $query): ?array
    {
        $board = $this->queries->board($projectId, $this->filter(['status' => 'all'] + $query)) ?? throw new NotFoundHttpException();
        foreach ($board->columns as $column) {
            $ids = array_map(static fn (TaskRow $task): string => $task->id, $column->tasks);
            $at = array_search($taskId, $ids, true);
            if (\is_int($at)) {
                if ($up) {
                    return $at > 0 ? [$column->id, $ids[$at - 1]] : null;
                }

                return $at < \count($ids) - 1 ? [$column->id, $ids[$at + 2] ?? ''] : null;
            }
        }

        return null;
    }

    /**
     * @param array<string, string>                 $seeds
     * @param list<array{id: string, name: string}> $statuses the columns of the board
     *
     * @return array<string, mixed>
     */
    private function card(TaskRow $task, array $seeds, string $statusId, array $statuses, int $at, int $count): array
    {
        $done = \count(array_filter($task->subtasks, static fn (TaskRow $subtask): bool => $subtask->done));

        return [
            'id' => $task->id,
            'href' => $this->generateUrl(TaskController::ROUTE, ['id' => $task->id]),
            'title' => $task->title,
            'responsible' => null === $task->responsibleId || null === $task->responsibleName ? null : [
                'name' => $task->responsibleName,
                'initials' => Avatar::initials($task->responsibleName),
                'tone' => Avatar::tone($seeds[$task->responsibleId] ?? $task->responsibleId),
            ],
            'due' => $this->due($task),
            'priority' => Priority::None === $task->priority ? null : $this->translator->trans('work.priority.'.$task->priority->value),
            'labels' => array_map(static fn (LabelBadge $label): array => ['label' => $label->name, 'tone' => $label->colour->value], $task->labels),
            'subtasks' => 0 === $task->subtaskCount ? null : ['done' => $done, 'total' => $task->subtaskCount],
            // Every status, its own hidden: a drop changes which one that is.
            'moveTo' => array_map(static fn (array $status): array => $status + ['current' => $status['id'] === $statusId], $statuses),
            'up' => $at > 0,
            'down' => $at < $count - 1,
            'deletable' => $task->deletable,
        ];
    }

    /**
     * @return array<string, string> user ids by membership id, for the avatar colours the people screen uses
     */
    private function seeds(): array
    {
        return array_column(array_map(static fn (Person $person): array => [$person->membershipId, $person->userId], $this->people->all()), 1, 0);
    }

    /**
     * @return array<string, string>
     */
    private function responsibles(): array
    {
        $responsibles = ['' => $this->translator->trans('work.filter.responsible_anyone'), 'me' => $this->translator->trans('work.filter.responsible_me')];
        foreach ($this->people->all() as $person) {
            if ($person->active) {
                $responsibles[$person->membershipId] = $person->name;
            }
        }

        return $responsibles;
    }

    /**
     * @return array<string, string>
     */
    private function dues(): array
    {
        return ['' => $this->translator->trans('work.filter.due_any')] + array_combine(
            array_map(static fn (DueBucket $due): string => $due->value, DueBucket::cases()),
            array_map(fn (DueBucket $due): string => $this->translator->trans('work.filter.due_'.$due->value), DueBucket::cases()),
        );
    }

    /**
     * @param array<string, string> $seeds user ids by membership id, for the avatar colours the people screen uses
     *
     * @return array{id: ?string, key: string, name: string, count: int, rows: list<array<string, mixed>>}
     */
    private function sectionView(SectionTasks $section, array $seeds): array
    {
        $rows = [];
        foreach ($section->tasks as $task) {
            $rows[] = $this->taskRow($task, $seeds, false);
            foreach ($task->subtasks as $subtask) {
                $rows[] = $this->taskRow($subtask, $seeds, true);
            }
        }

        return [
            'id' => $section->id,
            'key' => $section->id ?? self::NO_SECTION,
            'name' => $section->name ?? $this->translator->trans('work.list.no_section'),
            'count' => \count($section->tasks),
            'rows' => $rows,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function taskColumns(): array
    {
        return [
            ['key' => 'title', 'label' => $this->translator->trans('work.task.field.title'), 'truncate' => true],
            ['key' => 'status', 'label' => $this->translator->trans('work.task.field.status')],
            ['key' => 'responsible', 'label' => $this->translator->trans('work.task.field.responsible'), 'truncate' => true, 'phone' => false],
            ['key' => 'due', 'label' => $this->translator->trans('work.task.field.due_on'), 'phone' => false],
            ['key' => 'priority', 'label' => $this->translator->trans('work.task.field.priority'), 'phone' => false],
            ['key' => 'labels', 'label' => $this->translator->trans('work.task.field.labels'), 'phone' => false, 'wide' => true],
        ];
    }

    /**
     * @param array<string, string> $seeds
     *
     * @return array<string, mixed>
     */
    private function taskRow(TaskRow $task, array $seeds, bool $child): array
    {
        return [
            'id' => $task->id,
            'href' => $this->generateUrl(TaskController::ROUTE, ['id' => $task->id]),
            'label' => $task->title,
            'child' => $child,
            'cells' => [
                'title' => $task->title,
                'status' => $this->statusBadge($task->statusName, $task->statusType),
                'responsible' => null === $task->responsibleId || null === $task->responsibleName ? null : ['avatar' => $seeds[$task->responsibleId] ?? $task->responsibleId, 'text' => $task->responsibleName],
                'due' => $this->due($task),
                'priority' => Priority::None === $task->priority ? null : $this->translator->trans('work.priority.'.$task->priority->value),
                'labels' => [] === $task->labels ? null : ['badges' => array_map(static fn (LabelBadge $label): array => ['label' => $label->name, 'tone' => $label->colour->value], $task->labels)],
            ],
            'actions' => $task->deletable ? [[
                'label' => $this->translator->trans('work.trash.delete'),
                'href' => $this->generateUrl('app_task_delete', ['id' => $task->id]),
                'method' => 'POST',
                'fields' => ['from' => 'list'],
            ]] : [],
        ];
    }

    /**
     * @return array{text: string, tone: string}
     */
    private function statusBadge(string $name, StatusType $type): array
    {
        return ['text' => $name, 'tone' => self::STATUS_TONES[$type->value]];
    }

    /**
     * A plain date; an unfinished task past it says so in words on the red tone.
     *
     * @return string|array{text: string, tone: string}|null
     */
    private function due(TaskRow $task): string|array|null
    {
        if (!$task->dueOn instanceof \DateTimeImmutable) {
            return null;
        }
        $date = $this->date($task->dueOn);
        if (!$task->done && $task->dueOn->format('Y-m-d') < $this->clock->today()->format('Y-m-d')) {
            return ['text' => $this->translator->trans('work.list.overdue', ['date' => $date]), 'tone' => 'red'];
        }

        return $date;
    }

    /**
     * @return array<string, mixed>
     */
    private function projectRow(ProjectRow $project): array
    {
        return [
            'id' => $project->id,
            'href' => $this->generateUrl(self::PROJECT_ROUTE, ['id' => $project->id]),
            'label' => $project->name,
            'secondary' => $project->clientName,
            'cells' => [
                'name' => $project->name,
                'client' => null === $project->clientId ? null : ['links' => [['text' => $project->clientName ?? '', 'href' => $this->generateUrl('app_contact_show', ['id' => $project->clientId])]]],
                'open' => (string) $project->openTasks,
                'due' => $project->nearestDue instanceof \DateTimeImmutable ? $this->date($project->nearestDue) : null,
            ],
        ];
    }

    private function date(\DateTimeImmutable $date): string
    {
        return $date->format($this->translator->trans('format.date'));
    }

    private function denyUnlessStaff(): void
    {
        if (!$this->staff->isStaff()) {
            throw new AccessDeniedHttpException('Only the staff work on the projects of the organisation.');
        }
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
