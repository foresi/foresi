<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Work\Domain\Label;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\Section;
use App\Work\Domain\Task;
use App\Work\Domain\TaskLabel;
use App\Work\Domain\TaskWatcher;

/**
 * The tasks of the active tenant with their watchers and labels. A task in the
 * trash is found only by what asks for the trash.
 */
interface Tasks
{
    /**
     * A task that is not in the trash.
     */
    public function task(string $id): ?Task;

    /**
     * A task in the trash.
     */
    public function trashed(string $id): ?Task;

    /**
     * The tasks of a project directly under a task, or at the top when the
     * parent is null, those in the trash left out.
     *
     * @return list<Task> by position
     */
    public function siblings(Project $project, ?Task $parent): array;

    /**
     * Every subtask of a task, those in the trash included.
     *
     * @return list<Task> by position
     */
    public function subtasksOf(Task $parent): array;

    /**
     * The tasks put in the trash before the moment, subtasks included.
     *
     * @return list<Task>
     */
    public function trashedBefore(\DateTimeImmutable $moment): array;

    /**
     * The position after the last task of the project.
     */
    public function nextPosition(Project $project): int;

    /**
     * Those in the trash included.
     *
     * @return list<Task>
     */
    public function inStatus(ProjectStatus $status): array;

    /**
     * Those in the trash included.
     *
     * @return list<Task>
     */
    public function inSection(Section $section): array;

    /**
     * @return list<TaskWatcher>
     */
    public function watchers(Task $task): array;

    /**
     * @return list<TaskLabel>
     */
    public function labels(Task $task): array;

    /**
     * @return list<TaskLabel>
     */
    public function labelled(Label $label): array;

    public function add(Task|TaskWatcher|TaskLabel $item): void;

    /**
     * Removes for good; a task goes only once nothing points at it.
     */
    public function remove(Task|TaskWatcher|TaskLabel $item): void;
}
