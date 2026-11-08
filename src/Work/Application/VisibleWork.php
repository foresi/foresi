<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\Section;
use App\Work\Domain\Task;

/**
 * Loads what a command acts on, through WorkVisibility: what does not exist
 * and what the person may not see are both unknown, and so is a task in the
 * trash, except to what asks for the trash.
 */
final readonly class VisibleWork
{
    public function __construct(private Projects $projects, private Tasks $tasks, private WorkVisibility $visibility)
    {
    }

    /**
     * @throws WorkRefused
     */
    public function project(string $id): Project
    {
        $project = $this->projects->project($id);
        if (!$project instanceof Project || !$this->visibility->canSeeProject($project)) {
            throw new WorkRefused('unknown');
        }

        return $project;
    }

    /**
     * @throws WorkRefused
     */
    public function status(string $id): ProjectStatus
    {
        $status = $this->projects->status($id);
        if (!$status instanceof ProjectStatus || !$this->visibility->canSeeProject($status->project())) {
            throw new WorkRefused('unknown');
        }

        return $status;
    }

    /**
     * @throws WorkRefused
     */
    public function section(string $id): Section
    {
        $section = $this->projects->section($id);
        if (!$section instanceof Section || !$this->visibility->canSeeProject($section->project())) {
            throw new WorkRefused('unknown');
        }

        return $section;
    }

    /**
     * @throws WorkRefused
     */
    public function task(string $id): Task
    {
        $task = $this->tasks->task($id);
        if (!$task instanceof Task || !$this->visibility->canSeeTask($task)) {
            throw new WorkRefused('unknown');
        }

        return $task;
    }

    /**
     * A task in the trash, of a project the person may see.
     *
     * @throws WorkRefused
     */
    public function trashed(string $id): Task
    {
        $task = $this->tasks->trashed($id);
        if (!$task instanceof Task || !$this->visibility->canSeeProject($task->project())) {
            throw new WorkRefused('unknown');
        }

        return $task;
    }

    /**
     * @throws WorkRefused when the person may not start projects or keep labels
     */
    public function assertManager(): void
    {
        if (!$this->visibility->canManage()) {
            throw new WorkRefused('not_allowed');
        }
    }
}
