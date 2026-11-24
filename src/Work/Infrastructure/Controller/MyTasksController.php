<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Controller;

use Foresi\Identity\Application\OpenToGuest;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Application\View\FilterRows;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ListViews;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Shared\Infrastructure\View\FilterSheet;
use Foresi\Ui\Column\RememberedDisplay;
use Foresi\Ui\Contract\Opens;
use Foresi\Ui\Contract\WorkingDisplay;
use Foresi\Ui\Contract\WorkingState;
use Foresi\Ui\View\ViewBar;
use Foresi\Work\Application\TaskGroup;
use Foresi\Work\Application\TaskRow;
use Foresi\Work\Application\View\MyTaskLists;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Infrastructure\Column\MyTaskColumns;
use Foresi\Work\Infrastructure\Column\MyTaskLine;
use Foresi\Work\Infrastructure\View\MyTaskViews;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * My unfinished tasks across the active projects I see, a guest's too, by when they
 * are due, as the My Tasks of Asana, on saved views (spec D3, D7): All, Assigned to
 * me and Watching ship as views, with the person's own beside them. The client of the
 * project is a condition of the filter sheet (D11); an old ?tab= or ?client= link opens the
 * same tasks. A group with nothing in it is left out. A project known only by its
 * name, for a task shared on its own, is not a link.
 */
#[OpenToGuest]
final class MyTasksController extends AbstractController
{
    public const string ROUTE = 'app_my_tasks';
    private const int MAX_SEARCH = 100;

    public function __construct(
        private readonly WorkQueries $queries,
        private readonly InstanceClock $clock,
        private readonly TranslatorInterface $translator,
        private readonly InlineTaskEdits $edits,
        private readonly MyTaskViews $myTaskViews,
        private readonly RememberedDisplay $rememberedDisplay,
        private readonly ViewBar $viewBar,
        private readonly FilterSheet $filterSheet,
    ) {
    }

    #[Route('/my-tasks', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $input = $request->query;
        // The builder's rows (spec D11) become the list's working filter, in its address.
        if ($input->has(FilterRows::MARKER)) {
            return $this->redirectToRoute(self::ROUTE, $this->builtQuery($input), Response::HTTP_SEE_OTHER);
        }
        [$views, $view, $shown] = $this->myTaskViews->of($input);
        $list = MyTaskLists::list();
        $filterParam = WorkingFilter::param($shown, $view->filter);
        $text = trim(mb_substr($input->getString('q'), 0, self::MAX_SEARCH));
        $groups = TaskGroup::split($this->queries->myTaskList($shown, $text), $this->clock->today());
        $edits = $this->edits->for(array_merge(...array_map(static fn (TaskGroup $group): array => $group->tasks, $groups)), $request->getRequestUri());
        $query = array_filter(['view' => $view->isAll() ? null : $view->key, 'q' => '' === $text ? null : $text, WorkingFilter::PARAM => $filterParam], static fn (?string $value): bool => null !== $value);
        // The quick filters, columns and density changed in the Settings sheet, kept in the address next to the view and for the person (spec D10, D-display-3).
        $working = $this->rememberedDisplay->read(MyTaskColumns::LIST, [], $list->key, $view, $input->all(), filters: $list);
        $query += $working->query;
        $bar = $this->viewBar->of($list, $views, $view, $shown, '', '', $working, self::ROUTE, '' === $text ? [] : ['q' => $text]);

        return $this->render('@Work/my_tasks/index.html.twig', [
            'view' => $view->isAll() ? '' : $view->key,
            'working' => $filterParam ?? '',
            ...$this->filterSheet->of($list, $shown, fn (ViewFilter $next): string => $this->generateUrl(self::ROUTE, [WorkingFilter::PARAM => WorkingFilter::param($next, $view->filter)] + array_diff_key($query, [WorkingFilter::PARAM => true])), $this->references(), pinned: $working->pinned),
            ...$bar,
            ...$this->tabs($views, $view->key, $query, $bar['viewBar']['modified']),
            'text' => $text,
            'empty' => $this->emptyOf($view, $shown, '' !== $text),
            'columns' => $working->display->table(),
            'density' => $working->tableDensity(),
            'picker' => $this->picker($working, $query),
            'workingQuery' => $working->query,
            'resetHref' => $this->generateUrl(self::ROUTE, ['view' => $view->isAll() ? null : $view->key]),
            'groups' => array_values(array_map(fn (TaskGroup $group): array => [
                'key' => $group->key,
                'name' => $this->translator->trans('work.my_tasks.group.'.$group->key),
                'count' => \count($group->tasks),
                'rows' => array_map(fn (TaskRow $task): array => $this->row($task, $working, $edits), $group->tasks),
            ], array_filter($groups, static fn (TaskGroup $group): bool => [] !== $group->tasks))),
        ]);
    }

    /**
     * The empty state a view says: a shipped view as it ships, one changed in the
     * builder or a person's own that nothing matches.
     */
    private function emptyOf(ListView $view, ViewFilter $working, bool $searched = false): string
    {
        if ($searched || $working->toArray() !== $view->filter->toArray()) {
            return 'filtered';
        }

        return match ($view->slug) {
            ListDefinition::ALL => ListDefinition::ALL,
            MyTaskLists::ASSIGNED => MyTaskLists::ASSIGNED,
            MyTaskLists::WATCHING => MyTaskLists::WATCHING,
            default => 'filtered',
        };
    }

    /**
     * The list's address after the builder's Apply: what the sheet sent, its rows read
     * into the working filter, which is left out when it is the view's own. The old
     * parameters of the sheet are in the rows by then.
     *
     * @param InputBag<string> $input
     *
     * @return array<string, mixed>
     */
    private function builtQuery(InputBag $input): array
    {
        [, $view] = $this->myTaskViews->of($input);
        $query = array_diff_key($input->all(), [FilterRows::PARAM => true, FilterRows::MARKER => true, WorkingFilter::PARAM => true, 'view' => true, 'tab' => true] + MyTaskViews::OLD_PARAMS);

        return array_filter($query + ['view' => $view->isAll() ? null : $view->key, WorkingFilter::PARAM => WorkingFilter::param(FilterRows::parse($input->all(FilterRows::PARAM), MyTaskLists::list()), $view->filter)], static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * The contacts a client condition picks from, those the client of a project picks
     * from, and names: the clients of the active projects.
     *
     * @return array<string, array{url: string, name: \Closure(string): ?string}>
     */
    private function references(): array
    {
        return [MyTaskLists::CONTACT => [
            'url' => $this->generateUrl('app_project_clients'),
            'name' => fn (string $contact): ?string => $this->clients()[$contact] ?? null,
        ]];
    }

    /**
     * The tabs of the list and the other views of its Settings sheet (spec D7): All and the primary
     * views as tabs, none with a count (D9); the other views in the menu.
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
            // A view opens with its own filter and columns: the changed ones belong to the view they were changed on (spec D10).
            'href' => $this->generateUrl(self::ROUTE, ['view' => $view->isAll() ? null : $view->key] + array_diff_key($query, ['view' => true, WorkingFilter::PARAM => true] + array_flip(WorkingDisplay::KEYS))),
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
     * @return array<string, mixed>
     */
    private function row(TaskRow $task, WorkingState $working, TaskEdits $edits): array
    {
        return [
            'id' => $task->id,
            'href' => $this->generateUrl(TaskController::ROUTE, ['id' => $task->id]),
            'opens' => Opens::SHEET,
            'label' => $task->title,
            'secondary' => $task->projectName,
            'cells' => $working->display->cells(new MyTaskLine($task, $edits)),
        ];
    }
}
