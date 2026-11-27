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
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\FilterRows;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ListViews;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\ViewRules;
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
use Foresi\Work\Application\View\CalendarPeriod;
use Foresi\Work\Application\View\TaskLists;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Application\WorkVisibility;
use Foresi\Work\Contract\ProjectClients;
use Foresi\Work\Domain\Priority;
use Foresi\Work\Infrastructure\Column\MyTaskLine;
use Foresi\Work\Infrastructure\Column\TaskColumns;
use Foresi\Work\Infrastructure\Doctrine\TaskListDql;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
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
    /** At most this many tasks on a calendar's month, and of those with no due date beside it. */
    private const int CALENDAR_MAX = 500;
    private const int UNDATED_MAX = 50;
    /** A task id the route takes, for the address the drop of a row fills in. */
    private const string ID = '00000000-0000-7000-8000-000000000000';

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
        private readonly CsrfTokenManagerInterface $tokens,
        private readonly ?ProjectClients $projectClients = null,
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
        // The quick filters, columns and grouping changed in the Settings sheet, kept in the address and for the person (spec D10, D-display-3).
        $context = ['manage' => $this->visibility->canManage()];
        $working = $this->rememberedDisplay->read(TaskColumns::LIST, $context, $list->key, $view, $input->all(), groups: TaskLists::groupsOf($view->slug, $this->taskLists->hasClients()), filters: $list, calendar: true);
        $query += $working->query;
        $group = $working->display->groupBy ?? TaskLists::groupsOf($view->slug, $this->taskLists->hasClients())[0];
        $calendar = ViewRules::LAYOUT_CALENDAR === $working->display->layout;
        $period = $calendar ? CalendarPeriod::of($input->getString('period'), $input->getString('on'), $this->clock->today(), $working->display->weekends) : null;
        if ($period instanceof CalendarPeriod) {
            // Where the calendar is opened stays in the address, through a change of the view or the filter.
            $query += array_filter(['period' => CalendarPeriod::MONTH === $period->period ? null : $period->period, 'on' => $input->has('on') ? $period->anchor->format('Y-m-d') : null], static fn (?string $value): bool => null !== $value);
        }
        $bar = $this->viewBar->of($list, $views, $view, $shown, $sort, TaskLists::sortOf($view->slug), $working, self::ROUTE, '' === $text ? [] : ['q' => $text]);
        $common = [
            'view' => $view->key,
            'working' => $filterParam ?? '',
            'sortKept' => $viewSort === $sort ? null : $sort,
            ...$this->filterSheet->of($list, $shown, fn (ViewFilter $next): string => $this->generateUrl(self::ROUTE, [WorkingFilter::PARAM => WorkingFilter::param($next, $view->filter)] + array_diff_key($query, [WorkingFilter::PARAM => true])), $this->references(), pinned: $working->pinned),
            ...$bar,
            ...$this->tabs($views, $view->key, $query, $bar['viewBar']['modified']),
            'text' => $text,
            'picker' => $this->picker($working, $query, $group, $view),
            'workingQuery' => $working->query + array_intersect_key($query, ['period' => true, 'on' => true]),
            'resetHref' => $this->generateUrl(self::ROUTE, ['view' => $view->key]),
            'newTask' => $this->newTaskHref($shown, $request->getRequestUri()),
        ];
        if ($period instanceof CalendarPeriod) {
            return $this->render('@Work/tasks/index.html.twig', $common + [
                'calendar' => $this->calendar($period, $shown, $text, $query, $request),
                'groups' => [],
                'empty' => null,
                'pages' => 1,
            ]);
        }

        $page = max(1, $input->getInt('page', 1));
        $index = $this->queries->taskIndex(new TaskSearch($shown, $text, $sort, $group), ($page - 1) * self::PAGE, self::PAGE);
        $pages = max(1, (int) ceil($index->total / self::PAGE));
        $edits = $this->edits->for($index->rows, $request->getRequestUri());
        $reorder = $this->reorderable($shown, $sort, $group, $text, $pages);
        $groups = $this->groups($index->rows, $index->groups, $group, $working, $edits);
        if ($reorder) {
            $groups = $this->withMoves($groups, $request->getRequestUri());
        }

        return $this->render('@Work/tasks/index.html.twig', $common + [
            'calendar' => null,
            'empty' => $this->emptyOf($view, $shown, '' !== $text),
            'columns' => $working->display->table($sort, fn (string $next): string => $this->generateUrl(self::ROUTE, ['sort' => $next] + $query)),
            'groups' => $groups,
            'grouped' => TaskLists::GROUP_NONE !== $group,
            'reorder' => $reorder ? ['url' => str_replace(self::ID, '__id__', $this->generateUrl('app_task_star_move', ['id' => self::ID])), 'token' => $this->tokens->getToken(TaskController::TOKEN)->getValue(), 'label' => $this->translator->trans('work.tasks.reorder.drag')] : null,
            'actionToken' => $reorder ? $this->tokens->getToken(TaskController::TOKEN)->getValue() : null,
            'total' => $index->total,
            'page' => min($page, $pages),
            'pages' => $pages,
            'pageBase' => $this->generateUrl(self::ROUTE, $query),
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

        // A project's client is its hint where the address book is installed.
        $clients = $this->projectClients instanceof ProjectClients;

        return $this->json(['results' => array_map(static fn (ProjectRow $row): array => ['id' => $row->id, 'label' => $row->name, 'hint' => $clients ? $row->clientName ?? '' : ''], $rows)]);
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
     * The calendar of due dates (plan 37.5): the tasks of the view due in the period on their
     * days, the most urgent first; those of the view with no due date beside it, to be dragged
     * onto a day. A drop sets the due date with the task's own inline edit, so the history and
     * the notifications are those of any change of it.
     *
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function calendar(CalendarPeriod $period, ViewFilter $shown, string $text, array $query, Request $request): array
    {
        $today = $this->clock->today()->format('Y-m-d');
        $dated = $shown
            ->with(new Condition(TaskLists::DUE_ON, 'on_or_after', $period->from->format('Y-m-d')))
            ->with(new Condition(TaskLists::DUE_ON, 'on_or_before', $period->to->format('Y-m-d')));
        $index = $this->queries->taskIndex(new TaskSearch($dated, $text, '-'.TaskLists::SORT_PRIORITY), 0, self::CALENDAR_MAX);
        $undated = $this->queries->taskIndex(new TaskSearch($shown->with(new Condition(TaskLists::DUE_ON, 'is_empty')), $text, '-'.TaskLists::SORT_PRIORITY), 0, self::UNDATED_MAX);
        $return = $request->getRequestUri();
        $byDay = [];
        $weekend = 0;
        foreach ($index->rows as $task) {
            $day = $task->dueOn?->format('Y-m-d') ?? '';
            if (!$period->weekends && null !== $task->dueOn && CalendarPeriod::isWeekend($task->dueOn)) {
                ++$weekend;
            }
            $byDay[$day][] = $this->entry($task, $today);
        }
        $locale = $request->getLocale();
        $format = static fn (string $pattern, \DateTimeImmutable $day): string => (string) new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $day->getTimezone(), null, $pattern)->format($day);
        $weeks = array_map(fn (array $days): array => array_map(fn (\DateTimeImmutable $day): array => [
            'date' => $day->format('Y-m-d'),
            'number' => $day->format('j'),
            'label' => $format('EEEE d. MMMM y', $day),
            'own' => $period->owns($day),
            'today' => $day->format('Y-m-d') === $today,
            'tasks' => $byDay[$day->format('Y-m-d')] ?? [],
            'add' => $this->generateUrl(TaskCreateController::ROUTE, ['due' => $day->format('Y-m-d')] + $this->newTaskQuery($shown, $return)),
            'addLabel' => $this->translator->trans('work.tasks.calendar.add', ['date' => $format('d. MMMM', $day)]),
        ], $days), $period->weeks());
        $month = CalendarPeriod::MONTH === $period->period;
        $at = fn (array $change): string => $this->generateUrl(self::ROUTE, $change + array_diff_key($query, ['period' => true, 'on' => true]));
        $periodQuery = $month ? [] : ['period' => $period->period];

        return [
            'period' => $period->period,
            'title' => $month ? $format('LLLL y', $period->anchor) : $format('d. MMMM', $period->from).' – '.$format('d. MMMM y', $period->to),
            'weekdays' => array_map(static fn (\DateTimeImmutable $day): string => $format('cccccc', $day), $period->weeks()[0]),
            'weeks' => $weeks,
            'previous' => $at($periodQuery + ['on' => $period->previous()]),
            'next' => $at($periodQuery + ['on' => $period->next()]),
            'today' => $at($periodQuery),
            'periods' => array_map(fn (string $key): array => ['label' => $this->translator->trans('work.tasks.calendar.'.$key), 'href' => $at((CalendarPeriod::MONTH === $key ? [] : ['period' => $key]) + ['on' => ($month ? $period->anchor : $period->from)->format('Y-m-d')]), 'active' => $key === $period->period], CalendarPeriod::PERIODS),
            'weekend' => $weekend,
            'showWeekends' => $this->generateUrl(self::ROUTE, [WorkingDisplay::WEEKENDS => '1'] + $query),
            'more' => max(0, $index->total - \count($index->rows)),
            'max' => self::CALENDAR_MAX,
            'undated' => array_map(fn (TaskRow $task): array => $this->entry($task, $today), $undated->rows),
            'undatedMore' => max(0, $undated->total - \count($undated->rows)),
            'due' => [
                'url' => str_replace(self::ID, '__id__', $this->generateUrl('app_task_due', ['id' => self::ID])),
                'token' => $this->tokens->getToken(TaskController::TOKEN)->getValue(),
                'return' => $return,
            ],
        ];
    }

    /**
     * One task on the calendar: it opens in the sheet; a finished one is struck through, an
     * unfinished one past its due date is overdue.
     *
     * @return array<string, mixed>
     */
    private function entry(TaskRow $task, string $today): array
    {
        $due = $task->dueOn?->format('Y-m-d');

        return [
            'id' => $task->id,
            'href' => $this->generateUrl(TaskController::ROUTE, ['id' => $task->id]),
            'title' => $task->title,
            'project' => $task->projectName,
            // Only what stands out: a high or urgent priority in its tone (C8: no colour per project).
            'priority' => \in_array($task->priority, [Priority::High, Priority::Urgent], true) ? ['text' => $this->translator->trans('work.priority.'.$task->priority->value), 'tone' => TaskEdits::PRIORITY_TONES[$task->priority->value]] : null,
            'done' => $task->done,
            'overdue' => !$task->done && null !== $due && $due < $today,
        ];
    }

    /**
     * The address of New task from the list: in the project the filter names, else in the
     * first project of the client it names.
     */
    private function newTaskHref(ViewFilter $filter, string $return): string
    {
        return $this->generateUrl(TaskCreateController::ROUTE, $this->newTaskQuery($filter, $return));
    }

    /**
     * What New task from the list fills in: the project the filter names, else the first
     * project of the client it names, and where it goes back to.
     *
     * @return array<string, string>
     */
    private function newTaskQuery(ViewFilter $filter, string $return): array
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

        return array_filter(['project' => $project, 'return' => $return], static fn (?string $value): bool => null !== $value && '' !== $value);
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
     * Whether the rows are put in order by hand: My priorities, or a view of starred tasks,
     * in the person's order, ungrouped, on one page and not searched, so what is dragged is
     * the whole list (plan 37.2).
     */
    private function reorderable(ViewFilter $shown, string $sort, string $group, string $text, int $pages): bool
    {
        $starred = array_filter($shown->conditions, static fn (Condition $condition): bool => TaskLists::STARRED === $condition->field && 'is' === $condition->op && true === $condition->value);

        return [] !== $starred && TaskLists::SORT_MY_ORDER === $sort && TaskLists::GROUP_NONE === $group && '' === $text && 1 === $pages;
    }

    /**
     * Move up and Move down on each row of My priorities: before the row above, or before
     * the row after the next one (the end for the one before last).
     *
     * @param list<array<string, mixed>> $groups
     *
     * @return list<array<string, mixed>>
     */
    private function withMoves(array $groups, string $return): array
    {
        foreach ($groups as $at => $group) {
            /** @var list<array<string, mixed>> $rows */
            $rows = $group['rows'];
            $ids = array_map(static fn (array $row): string => \is_string($row['id'] ?? null) ? $row['id'] : '', $rows);
            foreach ($rows as $index => $row) {
                $href = $this->generateUrl('app_task_star_move', ['id' => $ids[$index]]);
                $move = fn (string $label, string $before): array => ['label' => $this->translator->trans($label), 'href' => $href, 'method' => 'POST', 'fields' => ['beforeTaskId' => $before, 'return' => $return]];
                $actions = [];
                if ($index > 0) {
                    $actions[] = $move('work.tasks.reorder.up', $ids[$index - 1]);
                }
                if ($index < \count($rows) - 1) {
                    $actions[] = $move('work.tasks.reorder.down', $ids[$index + 2] ?? '');
                }
                $rows[$index]['actions'] = $actions;
            }
            $groups[$at]['rows'] = $rows;
        }

        return $groups;
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

        return \in_array($view->slug, [ListDefinition::ALL, TaskLists::MINE, TaskLists::PRIORITIES, TaskLists::OVERDUE, TaskLists::UNASSIGNED, TaskLists::HIGH, TaskLists::NO_DUE], true) ? $view->slug : 'filtered';
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
            // The clients, only where the address book is installed.
            ...$this->projectClients instanceof ProjectClients ? [TaskLists::SOURCE_CONTACT => ['url' => $this->projectClients->pickerUrl(), 'name' => static fn (string $id): ?string => $clients[$id] ?? null]] : [],
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
            'resetHref' => $this->generateUrl(self::ROUTE, $kept + [WorkingDisplay::WIDTHS => '']),
            'modified' => $working->modified,
            'group' => $group,
            'groups' => array_map(fn (string $key): array => ['value' => $key, 'label' => $this->translator->trans('work.tasks.group_by.'.$key)], TaskLists::groupsOf($view->slug, $this->taskLists->hasClients())),
            'layouts' => array_map(fn (string $key): array => ['value' => $key, 'label' => $this->translator->trans('work.tasks.layout.'.$key)], ViewRules::LAYOUTS),
            'layout' => ViewRules::LAYOUT_CALENDAR === $working->display->layout ? ViewRules::LAYOUT_CALENDAR : ViewRules::LAYOUT_LIST,
            'weekends' => $working->display->weekends,
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
