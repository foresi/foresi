<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Shared\Application\View\ViewFilter;

/**
 * The read side of the work module. Every query shows only what WorkVisibility
 * lets the signed-in person see; a project or task outside it is null, as if
 * it did not exist. A task in the trash is shown by the trash only.
 */
interface WorkQueries
{
    /**
     * @return list<ProjectRow> by name
     */
    public function projects(bool $archived = false): array;

    /**
     * The projects of the index a view holds (saved views, spec D4); the archived ones
     * only when a condition of the view asks for them.
     *
     * A text narrows them to those whose name or client's name contains it, whatever its case.
     *
     * @return list<ProjectRow> by name, or by name backwards
     */
    public function projectIndex(ViewFilter $view, bool $descending = false, string $text = ''): array;

    public function projectList(string $projectId, TaskFilter $filter = new TaskFilter()): ?ProjectList;

    public function board(string $projectId, TaskFilter $filter = new TaskFilter()): ?ProjectBoard;

    public function projectSettings(string $projectId): ?ProjectSettings;

    /**
     * The tasks of the project in the trash that went there themselves.
     */
    public function trash(string $projectId): ?ProjectTrash;

    /**
     * How many tasks the trash of the project shows; 0 for a project not visible.
     */
    public function trashCount(string $projectId): int;

    /**
     * Unfinished tasks of active projects the signed-in person is responsible for.
     *
     * @return list<TaskGroup> overdue, today, upcoming, later and no date, each even when empty
     */
    public function myTasks(): array;

    /**
     * How many of the tasks of myTasks are overdue or due today.
     */
    public function myDueCount(): int;

    /**
     * Unfinished tasks of active projects the signed-in person watches.
     *
     * @return list<TaskRow> by due date, those without one last
     */
    public function watchedTasks(): array;

    /**
     * Unfinished tasks of active projects the signed-in person is responsible for or
     * watches, narrowed by a checked filter of the My tasks list (MyTaskLists) and by a text
     * their title or project name contains, whatever its case.
     *
     * @return list<TaskRow> by due date, those without one last
     */
    public function myTaskList(ViewFilter $filter, string $text = ''): array;

    public function task(string $taskId): ?TaskDetail;

    /**
     * What is shared with the signed-in person in the active projects: the
     * projects they see, and the tasks shared with them that no project of
     * theirs lists, grouped by the name of their project.
     */
    public function sharedWithMe(): SharedWork;

    /**
     * The comments and the history of a task in one timeline. A step that
     * wrote a comment is the comment, not a step of its own.
     *
     * @return list<DiscussionItem>|null oldest first; null when the task is not visible
     */
    public function discussion(string $taskId, DiscussionFilter $filter = DiscussionFilter::All): ?array;

    /**
     * Active people who can see the task, whom a comment on it can mention.
     *
     * @return list<MentionCandidate>|null by name; null when the task is not visible
     */
    public function mentionCandidates(string $taskId): ?array;

    /**
     * For the pickers of a list: the active people who see each task, who alone
     * can be responsible for it, as the task page offers them. A task one cannot
     * see is left out.
     *
     * @param list<string> $taskIds
     *
     * @return array<string, list<Choice>> by task id, the people in the order of the people screen
     */
    public function assignable(array $taskIds): array;

    /**
     * For the pickers of a list: the statuses of each project, in order. A
     * project one cannot see is left out.
     *
     * @param list<string> $projectIds
     *
     * @return array<string, list<StatusChoice>> by project id
     */
    public function statusesOf(array $projectIds): array;

    /**
     * @return list<LabelBadge> by name
     */
    public function labels(): array;

    /**
     * @return array<string, int> the number of tasks of each label by label id, those in the trash too; a label on no task is left out
     */
    public function labelUsage(): array;

    /**
     * The labels in the trash, the last deleted first; empty for whom does not manage labels.
     *
     * @return list<TrashedLabel>
     */
    public function labelTrash(): array;

    /**
     * The tasks the person sees, newest first, those after the id, at most
     * limit of them: a slice of the API. A task of an archived project is in
     * it only when the filter names its project.
     *
     * @param string|null $afterId the id of the last task of the slice before
     *
     * @return list<TaskRow> by id, the newest first, each with the number of its subtasks
     */
    public function taskSlice(TaskSliceFilter $filter, ?string $afterId, int $limit): array;

    /**
     * The projects the person sees, newest first, those after the id, at most limit of them.
     *
     * @return list<ProjectRow> by id, the newest first
     */
    public function projectSlice(ProjectSliceFilter $filter, ?string $afterId, int $limit): array;

    /**
     * A project the person sees, with its workflow and the sections they see.
     */
    public function project(string $projectId): ?ProjectDetail;

    /**
     * The active people who may be responsible for a task of the project: who
     * see all of it that is not private. With a task of it, those who see that
     * task, as assignable() answers them.
     *
     * @return list<Choice>|null by name, membership ids; null when the project, or the task in it, is not visible
     */
    public function projectPeople(string $projectId, ?string $taskId = null): ?array;
}
