<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api;

use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Application\View\AppliedView;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\SavedViews;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\View\SavedView;
use Foresi\Shared\Infrastructure\View\Api\ViewJson;
use Foresi\Work\Application\View\MyTaskLists;
use Foresi\Work\Application\View\ProjectLists;
use Foresi\Work\Application\View\TaskLists;
use Foresi\Work\Application\WorkQueries;

/**
 * The lists of projects and tasks read by cursor, for the REST API and
 * MCP alike: the newest first, a limit between 1 and 100 and one row more read
 * to know whether more follow. A cursor that is no id lists nothing. A saved
 * view of the tasks is one of the list work.tasks or work.my_tasks, of the
 * projects one of work.projects.
 */
final readonly class WorkLists
{
    public const int MAX_LIMIT = 100;

    public function __construct(
        private WorkQueries $queries,
        private SavedViews $views,
        private ScopeGuard $scope,
        private ViewJson $viewJson,
        private ProjectLists $projectLists,
        private TaskLists $taskLists,
        private WorkJson $json,
        private MyTaskLists $myTaskLists,
    ) {
    }

    /**
     * @throws \Foresi\Shared\Application\View\SavedViewRefused
     */
    public function tasks(TaskListQuery $query, ?string $viewId, mixed $filter, ?string $afterId): WorkPage
    {
        $list = $this->taskListOf($viewId);
        $applied = AppliedView::of($this->views, $this->scope, $list, $viewId, $filter);
        $open = TaskLists::KEY === $list->key && $applied->view instanceof SavedView;
        [$rows, $next] = $this->slice(fn (int $limit): array => $this->queries->taskSlice($query->filter($applied->filter, $open), $afterId, $limit), $afterId, $query->limit);

        return new WorkPage($this->json->tasks($rows), $next, $this->view($applied));
    }

    /**
     * The list a view of the tasks is of: work.my_tasks for one of My tasks, the list of all
     * tasks for any other and for none, whose fields hold those of My tasks too.
     *
     * @throws \Foresi\Shared\Application\View\SavedViewRefused
     */
    private function taskListOf(?string $viewId): ListDefinition
    {
        if (null === $viewId || '' === $viewId) {
            return $this->taskLists->list();
        }
        if (str_starts_with($viewId, MyTaskLists::KEY.'.')) {
            return $this->myTaskLists->list();
        }
        if (str_starts_with($viewId, TaskLists::KEY.'.')) {
            return $this->taskLists->list();
        }
        $this->scope->require(AppliedView::VIEWS_SCOPE);

        return MyTaskLists::KEY === $this->views->get($viewId)->listKey() ? $this->myTaskLists->list() : $this->taskLists->list();
    }

    /**
     * @throws \Foresi\Shared\Application\View\SavedViewRefused
     */
    public function projects(ProjectListQuery $query, ?string $viewId, mixed $filter, ?string $afterId): WorkPage
    {
        $applied = AppliedView::of($this->views, $this->scope, $this->projectLists->list(), $viewId, $filter);
        [$rows, $next] = $this->slice(fn (int $limit): array => $this->queries->projectSlice($query->filter($applied->filter), $afterId, $limit), $afterId, $query->limit);

        return new WorkPage($this->json->projects($rows), $next, $this->view($applied));
    }

    /**
     * @template T of object{id: string}
     *
     * @param \Closure(int): list<T> $read
     *
     * @return array{list<T>, string|null} the rows and the id the next slice starts after
     */
    private function slice(\Closure $read, ?string $afterId, int $limit): array
    {
        if (null !== $afterId && !Id::isValid($afterId)) {
            return [[], null];
        }
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $rows = $read($limit + 1);
        if (\count($rows) <= $limit) {
            return [$rows, null];
        }
        $rows = \array_slice($rows, 0, $limit);

        return [$rows, $rows[$limit - 1]->id];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function view(AppliedView $applied): ?array
    {
        return $applied->view instanceof SavedView ? $this->viewJson->view($applied->view) : null;
    }
}
