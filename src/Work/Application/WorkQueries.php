<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

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
     * @param string|null $clientId only the projects for this client
     *
     * @return list<TaskGroup> overdue, today, upcoming, later and no date, each even when empty
     */
    public function myTasks(?string $clientId = null): array;

    /**
     * How many of the tasks of myTasks are overdue or due today.
     */
    public function myDueCount(): int;

    /**
     * Unfinished tasks of active projects the signed-in person watches.
     *
     * @return list<TaskRow> by due date, those without one last
     */
    public function watchedTasks(?string $clientId = null): array;

    public function task(string $taskId): ?TaskDetail;

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
     * @return list<LabelBadge> by name
     */
    public function labels(): array;

    /**
     * @return array<string, int> the number of tasks of each label by label id; a label on no task is left out
     */
    public function labelUsage(): array;
}
