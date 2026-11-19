<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Controller;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\OpenToGuest;
use Foresi\Identity\Application\OwnerCheck;
use Foresi\Identity\Application\People;
use Foresi\Identity\Application\Person;
use Foresi\Identity\Application\StaffCheck;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Application\PartyRow;
use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\View\FilterRows;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ListViews;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\ViewState;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Shared\Infrastructure\View\FilterSheet;
use Foresi\Ui\Column\RememberedDisplay;
use Foresi\Ui\Contract\Opens;
use Foresi\Ui\Contract\WorkingDisplay;
use Foresi\Ui\Contract\WorkingState;
use Foresi\Ui\View\ViewBar;
use Foresi\Work\Application\DueBucket;
use Foresi\Work\Application\LabelBadge;
use Foresi\Work\Application\ManageProjects;
use Foresi\Work\Application\ManageTasks;
use Foresi\Work\Application\ProjectRow;
use Foresi\Work\Application\ProjectSummary;
use Foresi\Work\Application\PurgeDeletedTasks;
use Foresi\Work\Application\SectionTasks;
use Foresi\Work\Application\StatusTasks;
use Foresi\Work\Application\TaskFilter;
use Foresi\Work\Application\TaskRow;
use Foresi\Work\Application\TimeQueries;
use Foresi\Work\Application\TimeTarget;
use Foresi\Work\Application\TrashedTask;
use Foresi\Work\Application\TrashedTime;
use Foresi\Work\Application\View\ProjectLists;
use Foresi\Work\Application\View\ProjectTaskLists;
use Foresi\Work\Application\View\TaskConditions;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Domain\Priority;
use Foresi\Work\Domain\WorkflowTemplate;
use Foresi\Work\Infrastructure\Column\ProjectColumns;
use Foresi\Work\Infrastructure\Column\ProjectIndexRow;
use Foresi\Work\Infrastructure\Column\ProjectTaskColumns;
use Foresi\Work\Infrastructure\Twig\TimeFunctions;
use Foresi\Work\Infrastructure\View\ProjectTaskViews;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The projects of the organisation: the index of active and archived projects
 * and a new project in a dialog over it (the same URL is a page of its own),
 * for its staff; the list of a project, section by section, for whoever sees
 * it, a guest included, with what they see of it; a new task opens in a dialog
 * from the header of the list and of the board. The board shows the same tasks
 * as one column per status; a card moves by dragging it or by the move items
 * of its menu, which work as plain forms. The trash of a project lists the
 * tasks deleted from it, each restored by a button, for the staff.
 */
final class ProjectController extends AbstractController
{
    public const string ROUTE = 'app_projects';
    public const string PROJECT_ROUTE = 'app_project';
    public const string BOARD_ROUTE = 'app_project_board';
    public const string PEOPLE_ROUTE = 'app_project_people';
    public const string TRASH_ROUTE = 'app_project_trash';
    /** The menu forms of a card and the drop on the board share it. */
    public const string MOVE_TOKEN = 'work_task_move';
    public const string TOKEN = 'work_project';
    public const string TASK_TOKEN = 'work_task_add';
    /** The block of the tasks in no section. */
    public const string NO_SECTION = 'none';
    private const int MAX_QUERY = 100;
    /** The index's one sort, by name; "-name" backwards. */
    private const string SORT_NAME = 'name';
    private const array STATUS_FILTERS = ['open', 'all', 'finished'];
    private const array STATUS_TONES = ['open' => 'gray', 'active' => 'amber', 'done' => 'green', 'closed' => 'gray'];

    public function __construct(private readonly ProjectTaskLists $taskLists, private readonly ProjectLists $projectLists,
        private readonly WorkQueries $queries,
        private readonly ManageProjects $projects,
        private readonly ManageTasks $tasks,
        private readonly PartyQuery $parties,
        private readonly People $people,
        private readonly StaffCheck $staff,
        private readonly AuditActor $actor,
        private readonly InstanceClock $clock,
        private readonly TranslatorInterface $translator,
        private readonly TimeQueries $time,
        private readonly OwnerCheck $owner,
        private readonly InlineTaskEdits $edits,
        private readonly TimeFunctions $timeFormat,
        private readonly ProjectTaskViews $taskViews,
        private readonly ProjectTaskColumns $taskColumns,
        private readonly ListViews $listViews,
        private readonly ActorRole $actorRole,
        private readonly RememberedDisplay $rememberedDisplay,
        private readonly ViewBar $viewBar,
        private readonly FilterSheet $filterSheet,
    ) {
    }

    /**
     * A guest has no index of the projects of the organisation: what is shared with them is their start.
     *
     * The index runs on saved views as the other lists do: All (the projects not archived)
     * and Archived, with "+ Filter", the person's own views and the Columns sheet. The old
     * ?tab=archived opens the Archived view, and ?tab=active All, which shows the same.
     */
    #[OpenToGuest]
    #[Route('/projects', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->staff->isStaff()) {
            return $this->redirectToRoute(SharedWorkController::ROUTE);
        }
        $input = $request->query;
        // The builder's rows (spec D11) become the list's working filter, in its address.
        if ($input->has(FilterRows::MARKER)) {
            return $this->redirectToRoute(self::ROUTE, $this->builtQuery($input), Response::HTTP_SEE_OTHER);
        }
        [$views, $view, $shown] = $this->projectViews($input);
        $list = $this->projectLists->list();
        $filterParam = WorkingFilter::param($shown, $view->filter);
        // A view keeps its own order; the address names the sort only when it differs (spec D10).
        $viewSort = ViewState::sortParam($view->sort, self::SORT_NAME);
        $sort = $input->getString('sort', $viewSort);
        $sort = self::SORT_NAME === ltrim($sort, '-') ? $sort : self::SORT_NAME;
        $query = array_filter(['view' => $view->isAll() ? null : $view->key, 'sort' => $viewSort === $sort ? null : $sort, WorkingFilter::PARAM => $filterParam], static fn (?string $value): bool => null !== $value);
        // The columns and density changed in the Columns sheet, kept in the address next to the view and for the person (spec D10, D-display-3).
        $working = $this->rememberedDisplay->read(ProjectColumns::LIST, [], $list->key, $view, $input->all());
        $query += $working->query;
        $bar = $this->viewBar->of($list, $views, $view, $shown, $sort, self::SORT_NAME, $working, self::ROUTE, []);

        return $this->render('@Work/projects/index.html.twig', [
            'view' => $view->isAll() ? '' : $view->key,
            // All keeps its first-run words in the template; the shipped Archived its own; any other view that nothing matches.
            'emptyTitle' => $this->translator->trans(ProjectLists::ARCHIVED === $view->key ? 'work.projects.empty.archived_title' : 'work.projects.empty.view_title'),
            'working' => $filterParam ?? '',
            ...$this->filterSheet->of($list, $shown, fn (ViewFilter $next): string => $this->generateUrl(self::ROUTE, [WorkingFilter::PARAM => WorkingFilter::param($next, $view->filter)] + array_diff_key($query, [WorkingFilter::PARAM => true])), $this->clientReferences()),
            'sortKept' => $viewSort === $sort ? null : $sort,
            ...$bar,
            ...$this->viewTabs($views, $view->key, $query, $bar['viewBar']['modified']),
            'columns' => $working->display->table($sort, fn (string $next): string => $this->generateUrl(self::ROUTE, ['sort' => $next] + $query)),
            'rows' => array_map(fn (ProjectRow $project): array => $this->projectRow($project, $working), $this->queries->projectIndex($shown, str_starts_with($sort, '-'))),
            'density' => $working->tableDensity(),
            'picker' => $this->picker($working, $query),
            'workingQuery' => $working->query,
            'filtered' => null !== $filterParam,
            'resetHref' => $this->generateUrl(self::ROUTE, ['view' => $view->isAll() ? null : $view->key]),
        ]);
    }

    #[Route('/projects/new', name: 'app_project_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyUnlessStaff();
        $values = ['name' => '', 'clientId' => '', 'clientQuery' => '', 'description' => ''];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request, self::TOKEN);
            foreach (array_keys($values) as $field) {
                $values[$field] = 'description' === $field ? $request->request->getString($field) : trim($request->request->getString($field));
            }
            if ('' === $values['name']) {
                $errors['name'] = $this->translator->trans('work.project.error.name_required');
            }
            if ([] === $errors) {
                try {
                    $project = $this->projects->create($values['name'], '' === $values['clientId'] ? null : $values['clientId'], WorkflowTemplate::Simple, $values['description']);

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

        return $this->render('@Work/projects/new.html.twig', [
            'values' => $values,
            'errors' => $errors,
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

    /**
     * A project's tasks on saved views (spec D3, D7): All, which leaves out the
     * finished tasks as the list always did, the person's own tasks and the
     * finished ones ship as views, with the person's own beside them. Who is
     * responsible, the status and the due date are conditions of "+ Filter"
     * (D11); the views are the same in every project. List and Board stay a
     * switch of the layout above the views. A guest sees the views and filters
     * but does not save them, as only the staff keep views.
     */
    #[OpenToGuest]
    #[Route('/projects/{id}', name: self::PROJECT_ROUTE, requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function show(string $id, Request $request): Response
    {
        $input = $request->query;
        // The builder's rows (spec D11) become the list's working filter, in its address.
        if ($input->has(FilterRows::MARKER)) {
            return $this->redirectToRoute(self::PROJECT_ROUTE, ['id' => $id] + $this->taskBuiltQuery($input), Response::HTTP_SEE_OTHER);
        }
        [$views, $view, $shown] = $this->taskViews->of($input);
        $list = $this->queries->projectList($id, new TaskFilter(conditions: new TaskConditions($shown, $this->myMembership(), $this->clock->today()))) ?? throw new NotFoundHttpException();
        $definition = $this->taskLists->list();
        $query = array_filter([
            'view' => $view->isAll() ? null : $view->key,
            WorkingFilter::PARAM => WorkingFilter::param($shown, $view->filter),
        ], static fn (?string $value): bool => null !== $value);
        $context = [
            ProjectTaskColumns::SEEDS => $this->seeds(),
            ProjectTaskColumns::EDITS => $this->editsOf($list->project, array_merge(...array_map(static fn (SectionTasks $section): array => self::withSubtasks($section->tasks), $list->sections)), $request),
        ];
        // The columns and density changed in the Columns sheet, kept in the address next to the view and for the person (spec D10, D-display-3).
        $working = $this->rememberedDisplay->read(ProjectTaskColumns::LIST, $context, $definition->key, $view, $input->all());
        $query += $working->query;
        $bar = $this->viewBar->of($definition, $views, $view, $shown, '', '', $working, self::PROJECT_ROUTE, ['id' => $id]);
        $sections = [];
        foreach ($list->sections as $section) {
            // The tasks in no section only when there are some.
            if (null === $section->id && [] === $section->tasks) {
                continue;
            }
            $sections[] = $this->sectionView($section, $working);
        }

        return $this->render('@Work/projects/show.html.twig', [
            'project' => $list->project,
            'sections' => $sections,
            'filtered' => [] !== $shown->conditions,
            'view' => $view->isAll() ? '' : $view->key,
            'working' => $query[WorkingFilter::PARAM] ?? '',
            'workingQuery' => $working->query,
            // Only the staff keep views: a guest filters and changes the columns, and saves nothing.
            'viewsEditable' => $this->staff->isStaff(),
            ...$this->filterSheet->of($definition, $shown, fn (ViewFilter $next): string => $this->generateUrl(self::PROJECT_ROUTE, ['id' => $id, WorkingFilter::PARAM => WorkingFilter::param($next, $view->filter)] + array_diff_key($query, [WorkingFilter::PARAM => true])), $this->references()),
            ...$bar,
            ...$this->tabs($id, $views, $view->key, $bar['viewBar']['modified']),
            'columns' => $working->display->table(),
            'density' => $working->tableDensity(),
            'picker' => $this->taskPicker($id, $working, $query),
            'trashCount' => $this->queries->trashCount($id) + $this->time->trashCount($id),
            'timeLoggable' => $this->time->projectTarget($id) instanceof TimeTarget,
            // Only an owner sees rates, so only an owner sees the actions that bill time.
            'timeBilling' => $this->owner->isOwner(),
        ]);
    }

    /**
     * The people a responsible condition picks from, found by their name: whom the
     * filter of the list offered, and "Me" when the text fits it or the person's own name.
     */
    #[OpenToGuest]
    #[Route('/projects/people', name: self::PEOPLE_ROUTE, methods: ['GET'])]
    public function people(Request $request): JsonResponse
    {
        $text = mb_strtolower(trim(mb_substr($request->query->getString('q'), 0, self::MAX_QUERY)));
        $me = $this->myMembership();
        $results = [];
        foreach ($this->responsibles() as $value => $name) {
            $names = mb_strtolower(ProjectTaskLists::ME === $value ? $name.' '.$this->personName($me) : $name);
            if ('' !== $value && str_contains($names, $text)) {
                $results[] = ['id' => $value, 'label' => $name, 'hint' => ''];
            }
        }

        return $this->json(['results' => $results]);
    }

    /**
     * The project as one column per status, in the order of the statuses, with
     * the top-level tasks as cards in their order. The status filter of the
     * list does not apply: every status has its column.
     */
    #[OpenToGuest]
    #[Route('/projects/{id}/board', name: self::BOARD_ROUTE, requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function board(string $id, Request $request): Response
    {
        $query = $this->filterQuery($request->query);
        $board = $this->queries->board($id, $this->filter(['status' => 'all'] + $query)) ?? throw new NotFoundHttpException();
        $seeds = $this->seeds();
        $edits = $this->editsOf($board->project, array_merge(...array_map(static fn (StatusTasks $column): array => $column->tasks, $board->columns)), $request);
        $statuses = array_map(static fn (StatusTasks $column): array => ['id' => $column->id, 'name' => $column->name], $board->columns);

        return $this->render('@Work/projects/board.html.twig', [
            'project' => $board->project,
            'query' => ['responsible' => $query['responsible'], 'due' => $query['due']],
            'filtered' => '' !== $query['responsible'] || '' !== $query['due'],
            'responsibles' => $this->responsibles(),
            'dues' => $this->dues(),
            'timeLoggable' => $this->time->projectTarget($id) instanceof TimeTarget,
            // Only an owner sees rates, so only an owner sees the actions that bill time.
            'timeBilling' => $this->owner->isOwner(),
            'columns' => array_map(fn (StatusTasks $column): array => [
                'id' => $column->id,
                'name' => $column->name,
                'tone' => self::STATUS_TONES[$column->type->value],
                'type' => $this->translator->trans('work.board.type.'.$column->type->value),
                'count' => \count($column->tasks),
                'cards' => array_map(
                    fn (TaskRow $task, int $at): array => $this->card($task, $seeds, $column->id, $statuses, $at, \count($column->tasks), $edits),
                    $column->tasks,
                    array_keys($column->tasks),
                ),
            ], $board->columns),
        ]);
    }

    /**
     * The tasks and the time deleted from the project, the last first: the
     * subtasks deleted with their task are restored with it, so only it is
     * listed. Restoring is offered to whom may restore each one.
     */
    #[Route('/projects/{id}/trash', name: self::TRASH_ROUTE, requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function trash(string $id): Response
    {
        $this->denyUnlessStaff();
        $trash = $this->queries->trash($id) ?? throw new NotFoundHttpException();
        $here = $this->generateUrl(self::TRASH_ROUTE, ['id' => $id]);
        $deletedBy = ['key' => 'deletedBy', 'label' => $this->translator->trans('work.trash.column.deleted_by'), 'truncate' => true, 'phone' => false];
        $deletedAt = ['key' => 'deletedAt', 'label' => $this->translator->trans('work.trash.column.deleted_at')];

        return $this->render('@Work/projects/trash.html.twig', [
            'project' => $trash->project,
            'days' => PurgeDeletedTasks::DAYS,
            'columns' => [
                ['key' => 'title', 'label' => $this->translator->trans('work.task.field.title'), 'truncate' => true],
                ['key' => 'parent', 'label' => $this->translator->trans('work.trash.column.parent'), 'truncate' => true, 'phone' => false],
                $deletedBy,
                $deletedAt,
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
            'timeColumns' => [
                ['key' => 'workedOn', 'label' => $this->translator->trans('work.time.trash.column.worked_on')],
                ['key' => 'person', 'label' => $this->translator->trans('work.time.trash.column.person'), 'truncate' => true, 'phone' => false],
                ['key' => 'duration', 'label' => $this->translator->trans('work.time.trash.column.duration'), 'align' => 'right'],
                ['key' => 'task', 'label' => $this->translator->trans('work.time.trash.column.task'), 'truncate' => true, 'phone' => false],
                $deletedBy,
                $deletedAt,
            ],
            'timeRows' => array_map(fn (TrashedTime $time): array => [
                'id' => $time->id,
                'label' => $this->timeFormat->duration($time->minutes).' · '.$time->workedOn->format($this->translator->trans('format.date')),
                'secondary' => $time->taskTitle,
                'cells' => [
                    'workedOn' => $time->workedOn->format($this->translator->trans('format.date')),
                    'person' => $time->personName,
                    'duration' => $this->timeFormat->duration($time->minutes),
                    'task' => $time->taskTitle,
                    'deletedBy' => $time->deletedByName,
                    'deletedAt' => $time->deletedAt->format($this->translator->trans('format.datetime')),
                ],
                'actions' => $time->restorable ? [[
                    'label' => $this->translator->trans('work.trash.restore'),
                    'href' => $this->generateUrl('app_time_restore', ['id' => $time->id]),
                    'method' => 'POST',
                    'icon' => 'archive-restore',
                    'fields' => ['return' => $here],
                ]] : [],
            ], $this->time->trash($id)),
        ]);
    }

    /**
     * Moves a task on the board. A drop sends the status and the task it lands
     * before (none for the end of the column) and gets 204, or 422 with the
     * reason; the menu of a card sends a status, or a direction within its
     * column, and comes back to the board, where a refusal is told.
     */
    #[OpenToGuest]
    #[Route('/tasks/{id}/move', name: 'app_task_move', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function move(string $id, Request $request): Response
    {
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
     * A new task, by its title and its section: a dialog over the list or the
     * board (the same URL is a page of its own) that goes back to the view it
     * was opened from, with its filters. On the board the task starts in the
     * first open status, as every new task does. The sections offered are those
     * the person sees, and no section only when the whole project is open to them.
     */
    #[OpenToGuest]
    #[Route('/projects/{id}/tasks/new', name: 'app_project_task_new', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function newTask(string $id, Request $request): Response
    {
        $list = $this->queries->projectList($id) ?? throw new NotFoundHttpException();
        if (!$list->project->taskable) {
            throw new NotFoundHttpException('No task of the person may go into the project.');
        }
        $post = $request->isMethod('POST');
        $return = $this->returnPath($id, ($post ? $request->request : $request->query)->getString('return'));
        if ($list->project->archivedAt instanceof \DateTimeImmutable) {
            $this->addFlash('error', $this->translator->trans('work.refused.archived'));

            return $this->redirect($return, Response::HTTP_SEE_OTHER);
        }
        $sections = $list->project->open ? ['' => $this->translator->trans('work.list.no_section')] : [];
        foreach ($list->sections as $section) {
            if (null !== $section->id) {
                $sections[$section->id] = $section->name ?? '';
            }
        }
        $values = ['title' => '', 'section' => ($post ? $request->request : $request->query)->getString('section')];
        $errors = [];
        if (!$post && !isset($sections[$values['section']])) {
            $values['section'] = (string) array_key_first($sections);
        }
        if ($post) {
            $this->checkToken($request, self::TASK_TOKEN);
            $values['title'] = trim($request->request->getString('title'));
            if ('' === $values['title']) {
                $errors['title'] = $this->translator->trans('work.task.error.title_required');
            }
            if (!isset($sections[$values['section']])) {
                $errors['section'] = $this->translator->trans('work.task.error.section');
            }
            if ([] === $errors) {
                try {
                    $this->tasks->create($id, $values['title'], '' === $values['section'] ? null : $values['section']);
                    $this->addFlash('success', $this->translator->trans('work.task.added', ['title' => $values['title']]));

                    return $this->redirect($return, Response::HTTP_SEE_OTHER);
                } catch (WorkRefused $refused) {
                    if ('unknown' === $refused->reason) {
                        throw new NotFoundHttpException($refused->getMessage(), $refused);
                    }
                    $errors['name' === $refused->reason ? 'title' : 'form'] = $this->translator->trans('work.refused.'.$refused->reason);
                }
            }
        }

        return $this->render('@Work/projects/task_new.html.twig', [
            'project' => $list->project,
            'values' => $values,
            'errors' => $errors,
            'sections' => $sections,
            'return' => $return,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * The list or the board of the project with the view and the filters it had,
     * from the path a form was opened from; anything else is the list.
     */
    private function returnPath(string $projectId, string $return): string
    {
        $parts = parse_url($return);
        // Only a path of this installation: an address with a scheme or a host is the list.
        if (!\is_array($parts) || isset($parts['scheme']) || isset($parts['host'])) {
            $parts = [];
        }
        $path = $parts['path'] ?? '';
        $board = $path === $this->generateUrl(self::BOARD_ROUTE, ['id' => $projectId]);
        $fields = [];
        if ($board || $path === $this->generateUrl(self::PROJECT_ROUTE, ['id' => $projectId])) {
            parse_str($parts['query'] ?? '', $fields);
        }
        $field = static fn (string $name, string $default = ''): string => isset($fields[$name]) && \is_string($fields[$name]) ? $fields[$name] : $default;
        $query = $this->filters($field('responsible'), $field('status', 'open'), $field('due'));
        if ($board || 'open' === $query['status']) {
            unset($query['status']);
        }
        // The list's view, its working filter and its columns.
        $view = [];
        foreach ($board ? [] : ['view', WorkingFilter::PARAM, ...WorkingDisplay::KEYS] as $name) {
            $view[$name] = $field($name);
        }

        return $this->generateUrl($board ? self::BOARD_ROUTE : self::PROJECT_ROUTE, ['id' => $projectId] + array_filter($view + $query, static fn (string $value): bool => '' !== $value));
    }

    /**
     * @param InputBag<string> $input the query of a page, or the fields of a form that goes back to it
     *
     * @return array{responsible: string, status: string, due: string}
     */
    private function filterQuery(InputBag $input): array
    {
        return $this->filters($input->getString('responsible'), $input->getString('status', 'open'), $input->getString('due'));
    }

    /**
     * The filters of the list and the board, each one it does not know left at its default.
     *
     * @return array{responsible: string, status: string, due: string}
     */
    private function filters(string $responsible, string $status, string $due): array
    {
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
            // Someone without a membership is responsible for nothing.
            $responsible = $this->myMembership() ?? 'nobody';
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
    private function card(TaskRow $task, array $seeds, string $statusId, array $statuses, int $at, int $count, ?TaskEdits $edits): array
    {
        $done = \count(array_filter($task->subtasks, static fn (TaskRow $subtask): bool => $subtask->done));

        return [
            'id' => $task->id,
            'href' => $this->generateUrl(TaskController::ROUTE, ['id' => $task->id]),
            'title' => $task->title,
            'private' => $task->private,
            'responsible' => null === $task->responsibleId || null === $task->responsibleName ? null : [
                'name' => $task->responsibleName,
                'seed' => $seeds[$task->responsibleId] ?? $task->responsibleId,
            ],
            'due' => $this->taskColumns->due($task),
            'priority' => Priority::None === $task->priority ? null : $this->translator->trans('work.priority.'.$task->priority->value),
            'labels' => array_map(static fn (LabelBadge $label): array => ['label' => $label->name, 'tone' => $label->colour->value], $task->labels),
            'subtasks' => 0 === $task->subtaskCount ? null : ['done' => $done, 'total' => $task->subtaskCount],
            // Every status, its own hidden: a drop changes which one that is.
            'moveTo' => array_map(static fn (array $status): array => $status + ['current' => $status['id'] === $statusId], $statuses),
            'up' => $at > 0,
            'down' => $at < $count - 1,
            'deletable' => $task->deletable,
            // The responsible person, the due date and the priority change on the card; the column is its status.
            'edit' => [
                'responsible' => $edits?->responsible($task),
                'due' => $edits?->due($task),
                'priority' => $edits?->priority($task),
            ],
        ];
    }

    /**
     * The pickers of the tasks of a project page; none in an archived project,
     * whose board does not move either.
     *
     * @param list<TaskRow> $tasks
     */
    private function editsOf(ProjectSummary $project, array $tasks, Request $request): ?TaskEdits
    {
        return $project->archivedAt instanceof \DateTimeImmutable ? null : $this->edits->for($tasks, $request->getRequestUri());
    }

    /**
     * @param list<TaskRow> $tasks
     *
     * @return list<TaskRow> the tasks, each followed by its subtasks
     */
    private static function withSubtasks(array $tasks): array
    {
        $all = [];
        foreach ($tasks as $task) {
            $all[] = $task;
            array_push($all, ...$task->subtasks);
        }

        return $all;
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
        // A guest is not told who else the organisation works with: its own people only.
        $staff = $this->staff->isStaff();
        foreach ($this->people->all() as $person) {
            if ($person->active && ($staff || $person->role->isStaff())) {
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
     * @return array{id: ?string, key: string, name: string, count: int, private: bool, rows: list<array<string, mixed>>}
     */
    private function sectionView(SectionTasks $section, WorkingState $working): array
    {
        $rows = [];
        foreach ($section->tasks as $task) {
            $rows[] = $this->taskRow($task, false, $working);
            foreach ($task->subtasks as $subtask) {
                $rows[] = $this->taskRow($subtask, true, $working);
            }
        }

        return [
            'id' => $section->id,
            'key' => $section->id ?? self::NO_SECTION,
            'name' => $section->name ?? $this->translator->trans('work.list.no_section'),
            'count' => \count($section->tasks),
            'private' => $section->private,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(TaskRow $task, bool $child, WorkingState $working): array
    {
        $due = $this->taskColumns->due($task);

        return [
            'id' => $task->id,
            'href' => $this->generateUrl(TaskController::ROUTE, ['id' => $task->id]),
            'opens' => Opens::SHEET,
            'label' => $task->title,
            'child' => $child,
            // A phone hides the columns of who and when: the line under the title says them, and lets a long title wrap.
            'secondary' => implode(' · ', array_filter([$task->responsibleName ?? $this->translator->trans('work.task.nobody'), \is_array($due) ? $due['text'] : $due], \is_string(...))),
            'cells' => $working->display->cells($task),
            'actions' => $task->deletable ? [[
                'label' => $this->translator->trans('work.trash.delete'),
                'href' => $this->generateUrl('app_task_delete', ['id' => $task->id]),
                'method' => 'POST',
                'fields' => ['from' => 'list'],
            ]] : [],
        ];
    }

    /**
     * The views of the list as tabs and its "More views" menu (spec D7); no tab counts (D9).
     * A view opens with its own filter and columns (D10).
     *
     * @param list<ListView> $views
     *
     * @return array{tabs: list<array<string, mixed>>, moreViews: list<array<string, mixed>>}
     */
    private function tabs(string $id, array $views, string $active, bool $modified): array
    {
        [$primary, $overflow] = ListViews::placed($views);
        $link = fn (ListView $view): array => [
            'label' => $view->translated ? $this->translator->trans($view->name) : $view->name,
            'href' => $this->generateUrl(self::PROJECT_ROUTE, ['id' => $id, 'view' => $view->isAll() ? null : $view->key]),
            'active' => $view->key === $active,
            'modified' => $modified && $view->key === $active,
            'private' => $view->private,
        ];

        return ['tabs' => array_map($link, $primary), 'moreViews' => array_map($link, $overflow)];
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function taskPicker(string $id, WorkingState $working, array $query): array
    {
        $kept = array_diff_key($query, array_flip(WorkingDisplay::KEYS));

        return [
            'keep' => $kept,
            'columns' => $working->picker(),
            'density' => $working->density,
            // An empty density names the view's own display, which forgets the one remembered (D-display-3).
            'resetHref' => $this->generateUrl(self::PROJECT_ROUTE, ['id' => $id] + $kept + [WorkingDisplay::DENSITY => '']),
            'modified' => $working->modified,
        ];
    }

    /**
     * The list's address after the builder's Apply: what the sheet sent, its rows read
     * into the working filter, which is left out when it is the view's own. The old
     * filters are in the rows by then.
     *
     * @param InputBag<string> $input
     *
     * @return array<string, mixed>
     */
    private function taskBuiltQuery(InputBag $input): array
    {
        [, $view] = $this->taskViews->of($input);
        $query = array_diff_key($input->all(), [FilterRows::PARAM => true, FilterRows::MARKER => true, WorkingFilter::PARAM => true, 'view' => true] + ProjectTaskViews::OLD_PARAMS);

        return array_filter($query + ['view' => $view->isAll() ? null : $view->key, WorkingFilter::PARAM => WorkingFilter::param(FilterRows::parse($input->all(FilterRows::PARAM), $this->taskLists->list()), $view->filter)], static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * The people a responsible condition picks from and names: "Me", or anyone the
     * organisation has had, though no longer active.
     *
     * @return array<string, array{url: string, name: \Closure(string): ?string}>
     */
    private function references(): array
    {
        return [ProjectTaskLists::PERSON => [
            'url' => $this->generateUrl(self::PEOPLE_ROUTE),
            'name' => fn (string $membership): ?string => ProjectTaskLists::ME === $membership ? $this->translator->trans('work.filter.responsible_me') : $this->personName($membership),
        ]];
    }

    private function personName(?string $membership): ?string
    {
        foreach ($this->people->all() as $person) {
            if ($person->membershipId === $membership) {
                return $person->name;
            }
        }

        return null;
    }

    /**
     * The membership of the person looking; someone without one is responsible for nothing.
     */
    private function myMembership(): ?string
    {
        $userId = $this->actor->userId();

        return null === $userId ? null : $this->people->membershipOf($userId)?->id();
    }

    /**
     * @return array<string, mixed>
     */
    private function projectRow(ProjectRow $project, WorkingState $working): array
    {
        return [
            'id' => $project->id,
            'href' => $this->generateUrl(self::PROJECT_ROUTE, ['id' => $project->id]),
            // A project is a place to work in, not a record: it opens as a page (docs/design/overlays.md).
            'opens' => Opens::PAGE,
            'label' => $project->name,
            'secondary' => $project->clientName,
            'cells' => $working->display->cells(new ProjectIndexRow(
                $project->name,
                $project->clientName,
                null === $project->clientId ? null : $this->generateUrl('app_contact_show', ['id' => $project->clientId]),
                $project->openTasks,
                $project->nearestDue instanceof \DateTimeImmutable ? $this->date($project->nearestDue) : null,
            )),
        ];
    }

    /**
     * The index's views as its address names them (spec D10): the person's views, the one
     * ?view= names (or ?tab=, the address of the tabs the index had), All when none, and
     * the filter the list shows, the view's own unless f= changed it.
     *
     * @param InputBag<string> $input
     *
     * @return array{list<ListView>, ListView, ViewFilter}
     */
    private function projectViews(InputBag $input): array
    {
        $list = $this->projectLists->list();
        $views = $this->listViews->of($list, $this->actorRole->userId());
        $view = ListViews::chosen($views, $input->getString('view', $input->getString('tab')));

        return [$views, $view, WorkingFilter::decode($input->getString(WorkingFilter::PARAM), $list) ?? $view->filter];
    }

    /**
     * The index's address after the builder's Apply: what the sheet sent, its rows read
     * into the working filter, which is left out when it is the view's own.
     *
     * @param InputBag<string> $input
     *
     * @return array<string, mixed>
     */
    private function builtQuery(InputBag $input): array
    {
        [, $view] = $this->projectViews($input);
        $query = array_diff_key($input->all(), [FilterRows::PARAM => true, FilterRows::MARKER => true, WorkingFilter::PARAM => true, 'view' => true, 'tab' => true]);

        return array_filter($query + ['view' => $view->isAll() ? null : $view->key, WorkingFilter::PARAM => WorkingFilter::param(FilterRows::parse($input->all(FilterRows::PARAM), $this->projectLists->list()), $view->filter)], static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * The contacts a client condition picks from and names: any contact of the address
     * book, as the client field of a project offers.
     *
     * @return array<string, array{url: string, name: \Closure(string): ?string}>
     */
    private function clientReferences(): array
    {
        return [ProjectLists::CONTACT => [
            'url' => $this->generateUrl('app_project_clients'),
            'name' => fn (string $contact): ?string => $this->parties->find($contact)?->displayName(),
        ]];
    }

    /**
     * The tabs of the index and its "More views" menu (spec D7): All and the primary views
     * as tabs, the other views in the menu. The index counts none (D9).
     *
     * @param list<ListView>        $views
     * @param array<string, string> $query
     *
     * @return array{tabs: list<array<string, mixed>>, moreViews: list<array<string, mixed>>}
     */
    private function viewTabs(array $views, string $active, array $query, bool $modified): array
    {
        [$primary, $overflow] = ListViews::placed($views);
        $link = fn (ListView $view): array => [
            'label' => $view->translated ? $this->translator->trans($view->name) : $view->name,
            // A view opens with its own filter, sort and columns: the changed ones belong to the view they were changed on (spec D10).
            'href' => $this->generateUrl(self::ROUTE, ['view' => $view->isAll() ? null : $view->key] + array_diff_key($query, ['view' => true, 'sort' => true, WorkingFilter::PARAM => true] + array_flip(WorkingDisplay::KEYS))),
            'active' => $view->key === $active,
            'modified' => $modified && $view->key === $active,
            'private' => $view->private,
        ];

        return ['tabs' => array_map($link, $primary), 'moreViews' => array_map($link, $overflow)];
    }

    /**
     * The Columns sheet: what it keeps of the index, and the index without the changed columns.
     *
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function picker(WorkingState $working, array $query): array
    {
        $kept = array_diff_key($query, array_flip(WorkingDisplay::KEYS));

        return [
            'keep' => $kept,
            'columns' => $working->picker(),
            'density' => $working->density,
            // An empty density names the view's own display, which forgets the one remembered (D-display-3).
            'resetHref' => $this->generateUrl(self::ROUTE, $kept + [WorkingDisplay::DENSITY => '']),
            'modified' => $working->modified,
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
