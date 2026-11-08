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
 * The tasks of the active tenant with their watchers and labels.
 */
interface Tasks
{
    public function task(string $id): ?Task;

    /**
     * The tasks of a project directly under a task, or at the top when the parent is null.
     *
     * @return list<Task> by position
     */
    public function siblings(Project $project, ?Task $parent): array;

    /**
     * The position after the last task of the project.
     */
    public function nextPosition(Project $project): int;

    /**
     * @return list<Task>
     */
    public function inStatus(ProjectStatus $status): array;

    /**
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

    public function remove(TaskWatcher|TaskLabel $item): void;
}
