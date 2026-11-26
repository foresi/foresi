<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Controller;

use Foresi\Identity\Application\OpenToGuest;
use Foresi\Identity\Application\People;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\View\FilterRows;
use Foresi\Shared\Application\View\ListDefinition;
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
use Foresi\Work\Application\ProjectRow;
use Foresi\Work\Application\TaskRow;
use Foresi\Work\Application\TaskSearch;
use Foresi\Work\Application\View\TaskLists;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Application\WorkVisibility;
use Foresi\Work\Infrastructure\Column\MyTaskLine;
use Foresi\Work\Infrastructure\Column\TaskColumns;
use Foresi\Work\Infrastructure\Doctrine\TaskListDql;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every task the person sees across the active projects (TaskLists), the home of the
 * Work area (plan 37.1): its shipped views and the person's own, the filter sheet and
 * the Settings sheet as every list has them, a sort by the column headers and the
 * groups the view keeps, both saved with the view (spec D10). Mine is where the list
 * opens; All is ?view=all. A page holds PAGE tasks; a group says how many tasks it
 * holds on every page, and one with nothing on the page is left out.
 */
#[OpenToGuest]
final class TaskListController extends AbstractController
{
    public const string ROUTE = 'app_tasks';
    public const int PAGE = 100;
    private const int MAX_SEARCH = 100;
    private const int MAX_LOOKUP = 20;

    public function __construct(
        private readonly WorkQueries $queries,
        private readonly TaskLists $taskLists,
        private readonly ListViews $views,
        private readonly ActorRole $actor,
        private readonly InstanceClock $clock,
        private readonly TranslatorInterface $translator,
        private readonly InlineTaskEdits $edits,
        private readonly RememberedDisplay $rememberedDisplay,
        private readonly ViewBar $viewBar,
        private readonly FilterSheet $filterSheet,
        private readonly WorkVisibility $visibility,
        private readonly People $people,
    ) {
    }

    #[Route('/tasks', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $input = $request->query;
        $list = $this->taskLists->list();
        // The builder's rows (spec D11) become the list's working filter, in its address.
        if ($input->has(FilterRows::MARKER)) {
            return $this->redirectToRoute(self::ROUTE, $this->builtQuery($input, $list), Response::HTTP_SEE_OTHER);
        }
        [$views, $view, $shown] = $this->viewsOf($input, $list);
        $filterParam = WorkingFilter::param($shown, $view->filter);
        $text = trim(mb_substr($input->getString('q'), 0, self::MAX_SEARCH));
        $viewSort = ViewState::sortParam($view->sort, TaskLists::sortOf($view->slug));
        $sort = TaskLists::sort($input->getString('sort', $viewSort), $viewSort);
        $query = array_filter(['view' => $view->key, 'q' => '' === $text ? null : $text, 'sort' => $viewSort === $sort ? null : $sort, WorkingFilter::PARAM => $filterParam], static fn (?string $value): bool => null !== $value);
        // The quick filters, columns, density and grouping changed in the Settings sheet, kept in the address and for the person (spec D10, D-display-3).
        $context = ['manage' => $this->visibility->canManage()];
        $working = $this->rememberedDisplay->read(TaskColumns::LIST, $context, $list->key, $view, $input->all(), groups: TaskLists::groupsOf($view->slug), filters: $list);
        $query += $working->query;
        $group = $working->display->groupBy ?? TaskLists::groupsOf($view->slug)[0];

        $page = max(1, $input->getInt('page', 1));
        $index = $this->queries->taskIndex(new TaskSearch($shown, $text, $sort, $group), ($page - 1) * self::PAGE, self::PAGE);
        $pages = max(1, (int) ceil($index->total / self::PAGE));
        $edits = $this->edits->for($index->rows, $request->getRequestUri());
        $bar = $this->viewBar->of($list, $views, $view, $shown, $sort, TaskLists::sortOf($view->slug), $working, self::ROUTE, '' === $text ? [] : ['q' => $text]);

        return $this->render('@Work/tasks/index.html.twig', [
            'view' => $view->key,
            'working' => $filterParam ?? '',
            'sortKept' => $viewSort === $sort ? null : $sort,
            ...$this->filterSheet->of($list, $shown, fn (ViewFilter $next): string => $this->generateUrl(self::ROUTE, [WorkingFilter::PARAM => WorkingFilter::param($next, $view->filter)] + array_diff_key($query, [WorkingFilter::PARAM => true])), $this->references(), pinned: $working->pinned),
            ...$bar,
            ...$this->tabs($views, $view->key, $query, $bar['viewBar']['modified']),
            'text' => $text,
            'empty' => $this->emptyOf($view, $shown, '' !== $text),
            'columns' => $working->display->table($sort, fn (string $next): string => $this->generateUrl(self::ROUTE, ['sort' => $next] + $query)),
            'density' => $working->tableDensity(),
            'picker' => $this->picker($working, $query, $group, $view),
            'workingQuery' => $working->query,
            'resetHref' => $this->generateUrl(self::ROUTE, ['view' => $view->key]),
            'groups' => $this->groups($index->rows, $index->groups, $group, $working, $edits),
            'grouped' => TaskLists::GROUP_NONE !== $group,
            'total' => $index->total,
            'page' => min($page, $pages),
            'pages' => $pages,
            'pageBase' => $this->generateUrl(self::ROUTE, $query),
            'newTask' => $this->newTaskHref($shown, $request->getRequestUri()),
        ]);
    }

    /**
     * The projects a filter's project condition picks from, and a new task goes in: those the
     * person sees by their name or their client's, the last used first.
     */
    #[Route('/tasks/projects', name: 'app_task_list_projects', methods: ['GET'])]
    public function projects(Request $request): JsonResponse
    {
        $text = $request->query->getString('q');
        $rows = $request->query->getBoolean('new') ? $this->queries->taskableProjects($text, self::MAX_LOOKUP) : $this->matching($text);

        return $this->json(['results' => array_map(static fn (ProjectRow $row): array => ['id' => $row->id, 'label' => $row->name, 'hint' => $row->clientName ?? ''], $rows)]);
    }

    /**
     * The labels a label condition picks from, by their name.
     */
    #[Route('/tasks/labels', name: 'app_task_list_labels', methods: ['GET'])]
    public function labels(Request $request): JsonResponse
    {
        $text = mb_strtolower(trim(mb_substr($request->query->getString('q'), 0, self::MAX_SEARCH)));
        $results = [];
        foreach ($this->queries->labels() as $label) {
            if ('' === $text || str_contains(mb_strtolower($label->name), $text)) {
                $results[] = ['id' => $label->id, 'label' => $label->name, 'hint' => ''];
            }
        }

        return $this->json(['results' => \array_slice($results, 0, self::MAX_LOOKUP)]);
    }

    /**
     * The address of New task from the list: in the project the filter names, else in the
     * first project of the client it names.
     */
    private function newTaskHref(ViewFilter $filter, string $return): string
    {
        $project = null;
        foreach ($filter->conditions as $condition) {
            if (TaskLists::PROJECT === $condition->field && 'is' === $condition->op && \is_string($condition->value)) {
                $project = $condition->value;
            }
            if (null === $project && TaskLists::CLIENT === $condition->field && 'is' === $condition->op && \is_string($condition->value)) {
                foreach ($this->queries->taskableProjects() as $row) {
                    if ($row->clientId === $condition->value) {
                        $project = $row->id;
                        break;
                    }
                }
            }
        }

        return $this->generateUrl(TaskCreateController::ROUTE, array_filter(['project' => $project, 'return' => $return], static fn (?string $value): bool => null !== $value && '' !== $value));
    }

    /**
     * @return list<ProjectRow>
     */
    private function matching(string $text): array
    {
        $text = mb_strtolower(trim(mb_substr($text, 0, self::MAX_SEARCH)));
        $rows = array_values(array_filter($this->queries->projects(), static fn (ProjectRow $row): bool => '' === $text || str_contains(mb_strtolower($row->name.' '.($row->clientName ?? '')), $text)));

        return \array_slice($rows, 0, self::MAX_LOOKUP);
    }

    /**
     * The views of the list, the one the address names, Mine when it names none, and the filter
     * the list shows: the view's own unless f= changed it.
     *
     * @param InputBag<string> $input
     *
     * @return array{list<ListView>, ListView, ViewFilter}
     */
    private function viewsOf(InputBag $input, ListDefinition $list): array
    {
        $views = $this->views->of($list, $this->actor->userId());
        $view = ListViews::chosen($views, $input->getString('view', TaskLists::MINE));
        $working = WorkingFilter::decode($input->getString(WorkingFilter::PARAM), $list) ?? $view->filter;

        return [$views, $view, $working];
    }

    /**
     * The list's address after the builder's Apply: what the sheet sent, its rows read
     * into the working filter, which is left out when it is the view's own.
     *
     * @param InputBag<string> $input
     *
     * @return array<string, mixed>
     */
    private function builtQuery(InputBag $input, ListDefinition $list): array
    {
        [, $view] = $this->viewsOf($input, $list);
        $query = array_diff_key($input->all(), [FilterRows::PARAM => true, FilterRows::MARKER => true, WorkingFilter::PARAM => true, 'view' => true, 'page' => true]);

        return array_filter($query + ['view' => $view->key, WorkingFilter::PARAM => WorkingFilter::param(FilterRows::parse($input->all(FilterRows::PARAM), $list), $view->filter)], static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * The empty state a view says: a shipped view as it ships, one changed or a person's own
     * that nothing matches.
     */
    private function emptyOf(ListView $view, ViewFilter $working, bool $searched): string
    {
        if ($searched || $working->toArray() !== $view->filter->toArray()) {
            return 'filtered';
        }

        return \in_array($view->slug, [ListDefinition::ALL, TaskLists::MINE, TaskLists::OVERDUE, TaskLists::UNASSIGNED, TaskLists::HIGH, TaskLists::NO_DUE], true) ? $view->slug : 'filtered';
    }

    /**
     * What the pickers of the filter sheet look up and how a chip names what it picked.
     *
     * @return array<string, array{url: string, name: \Closure(string): ?string}>
     */
    private function references(): array
    {
        $projects = [];
        $clients = [];
        foreach ($this->queries->projects() as $project) {
            $projects[$project->id] = $project->name;
            if (null !== $project->clientId) {
                $clients[$project->clientId] = $project->clientName ?? '';
            }
        }
        $labels = [];
        foreach ($this->queries->labels() as $label) {
            $labels[$label->id] = $label->name;
        }
        $people = [];
        foreach ($this->people->all() as $person) {
            $people[$person->membershipId] = $person->name;
        }
        $people[TaskLists::ME] = $this->translator->trans('work.filter.responsible_me');

        return [
            TaskLists::SOURCE_PROJECT => ['url' => $this->generateUrl('app_task_list_projects'), 'name' => static fn (string $id): ?string => $projects[$id] ?? null],
            TaskLists::SOURCE_CONTACT => ['url' => $this->generateUrl('app_project_clients'), 'name' => static fn (string $id): ?string => $clients[$id] ?? null],
            TaskLists::SOURCE_PERSON => ['url' => $this->generateUrl(ProjectController::PEOPLE_ROUTE), 'name' => static fn (string $id): ?string => $people[$id] ?? null],
            TaskLists::SOURCE_LABEL => ['url' => $this->generateUrl('app_task_list_labels'), 'name' => static fn (string $id): ?string => $labels[$id] ?? null],
        ];
    }

    /**
     * The tabs of the list and the other views of its Settings sheet (spec D7).
     *
     * @param list<ListView>        $views
     * @param array<string, string> $query
     *
     * @return array{tabs: list<array<string, mixed>>, moreViews: list<array<string, mixed>>}
     */
    private function tabs(array $views, string $active, array $query, bool $modified): array
    {
        [$primary, $overflow] = ListViews::placed($views);
        $link = fn (ListView $view): array => [
            'label' => $view->translated ? $this->translator->trans($view->name) : $view->name,
            // A view opens with its own filter, sort and display (spec D10).
            'href' => $this->generateUrl(self::ROUTE, ['view' => $view->key] + array_diff_key($query, ['view' => true, 'sort' => true, WorkingFilter::PARAM => true] + array_flip(WorkingDisplay::KEYS))),
            'active' => $view->key === $active,
            'modified' => $modified && $view->key === $active,
            'private' => $view->private,
        ];

        return ['tabs' => array_map($link, $primary), 'moreViews' => array_map($link, $overflow)];
    }

    /**
     * The Settings sheet's state, with what the list can group by.
     *
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function picker(WorkingState $working, array $query, string $group, ListView $view): array
    {
        $kept = array_diff_key($query, array_flip(WorkingDisplay::KEYS));

        return [
            'keep' => $kept,
            'columns' => $working->picker(),
            'density' => $working->density,
            'resetHref' => $this->generateUrl(self::ROUTE, $kept + [WorkingDisplay::DENSITY => '']),
            'modified' => $working->modified,
            'group' => $group,
            'groups' => array_map(fn (string $key): array => ['value' => $key, 'label' => $this->translator->trans('work.tasks.group_by.'.$key)], TaskLists::groupsOf($view->slug)),
        ];
    }

    /**
     * The page's tasks in their groups, each group named and counted over the whole list.
     *
     * @param list<TaskRow>      $rows
     * @param array<string, int> $counts
     *
     * @return list<array<string, mixed>>
     */
    private function groups(array $rows, array $counts, string $group, WorkingState $working, TaskEdits $edits): array
    {
        $groups = [];
        foreach ($rows as $task) {
            [$key, $name] = $this->groupOf($task, $group);
            $groups[$key] ??= ['key' => '' === $key ? 'none' : $key, 'name' => $name, 'count' => $counts[$key] ?? 0, 'rows' => []];
            $groups[$key]['rows'][] = $this->row($task, $working, $edits);
        }

        return array_values($groups);
    }

    /**
     * @return array{string, string} the key of the task's group, as the counts name it, and its name
     */
    private function groupOf(TaskRow $task, string $group): array
    {
        $none = fn (string $what): string => $this->translator->trans('work.tasks.group.no_'.$what);

        return match ($group) {
            TaskLists::GROUP_CLIENT => [$task->clientId ?? '', $task->clientName ?? $none('client')],
            TaskLists::GROUP_PROJECT => [$task->projectId, $task->projectName],
            TaskLists::GROUP_RESPONSIBLE => [$task->responsibleId ?? '', $task->responsibleName ?? $none('responsible')],
            TaskLists::GROUP_PRIORITY => [$task->priority->value, $this->translator->trans('work.priority.'.$task->priority->value)],
            TaskLists::GROUP_STATUS_TYPE => [$task->statusType->value, $this->translator->trans('work.settings.type.'.$task->statusType->value)],
            TaskLists::GROUP_DUE => [$bucket = $this->bucket($task), $this->translator->trans('work.my_tasks.group.'.$bucket)],
            default => ['', ''],
        };
    }

    /**
     * The due bucket of a task as TaskListDql ranks it.
     */
    private function bucket(TaskRow $task): string
    {
        $due = $task->dueOn?->format('Y-m-d');
        $today = $this->clock->today()->format('Y-m-d');

        return TaskListDql::DUE_GROUPS[match (true) {
            null === $due => 4,
            $due < $today => 0,
            $due === $today => 1,
            $due <= $this->clock->today()->modify('+7 days')->format('Y-m-d') => 2,
            default => 3,
        }];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(TaskRow $task, WorkingState $working, TaskEdits $edits): array
    {
        return [
            'id' => $task->id,
            'href' => $this->generateUrl(TaskController::ROUTE, ['id' => $task->id]),
            'opens' => Opens::SHEET,
            'label' => $task->title,
            // A subtask says whose it is.
            'secondary' => $task->parentTitle,
            'cells' => $working->display->cells(new MyTaskLine($task, $edits)),
        ];
    }
}
