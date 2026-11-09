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
 * trash, except to what asks for the trash. What only the people of the
 * organisation change is loaded as managed: unknown first, then not allowed.
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
     * A project whose settings only the people of the organisation change.
     *
     * @throws WorkRefused
     */
    public function managedProject(string $id): Project
    {
        $project = $this->project($id);
        $this->assertManager();

        return $project;
    }

    /**
     * A status of a project the person sees, or of a project of a task they see:
     * the workflow is what moves the task along.
     *
     * @throws WorkRefused
     */
    public function status(string $id): ProjectStatus
    {
        $status = $this->projects->status($id);
        if (!$status instanceof ProjectStatus || !$this->visibility->canSeeProjectName($status->project())) {
            throw new WorkRefused('unknown');
        }

        return $status;
    }

    /**
     * @throws WorkRefused
     */
    public function managedStatus(string $id): ProjectStatus
    {
        $status = $this->status($id);
        if (!$this->visibility->canSeeProject($status->project())) {
            throw new WorkRefused('unknown');
        }
        $this->assertManager();

        return $status;
    }

    /**
     * @throws WorkRefused
     */
    public function section(string $id): Section
    {
        $section = $this->projects->section($id);
        if (!$section instanceof Section || !$this->visibility->canSeeSection($section)) {
            throw new WorkRefused('unknown');
        }

        return $section;
    }

    /**
     * @throws WorkRefused
     */
    public function managedSection(string $id): Section
    {
        $section = $this->section($id);
        $this->assertManager();

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
     * A task in the trash the person would see out of it.
     *
     * @throws WorkRefused
     */
    public function trashed(string $id): Task
    {
        $task = $this->tasks->trashed($id);
        if (!$task instanceof Task || !$this->visibility->canSeeTask($task)) {
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

    /**
     * @throws WorkRefused when the person may not share the item or change who sees it
     */
    public function assertSharer(Project|Section|Task $item): void
    {
        if (!$this->visibility->canShare($item)) {
            throw new WorkRefused('not_allowed');
        }
    }

    /**
     * @throws WorkRefused when no task of the person may go there
     */
    public function assertTaskIn(Project $project, ?Section $section): void
    {
        if (!$this->visibility->canCreateTaskIn($project, $section)) {
            throw new WorkRefused('unknown');
        }
    }
}
