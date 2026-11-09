<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\StatusType;
use Psr\Clock\ClockInterface;

/**
 * The statuses of a project, the columns of its board. A project always keeps
 * a status of type open, where new tasks start, and one of type done, where
 * they finish; the tasks of a removed status move to another of the project.
 */
final readonly class ManageWorkflow
{
    public function __construct(
        private Projects $projects,
        private Tasks $tasks,
        private VisibleWork $work,
        private ClockInterface $clock,
        private WorkUnit $unit,
    ) {
    }

    /**
     * @param int|null $position counted from 0; null puts the status last
     *
     * @throws WorkRefused
     */
    public function add(string $projectId, string $name, StatusType $type, ?int $position = null): ProjectStatus
    {
        $project = $this->work->managedProject($projectId);
        $statuses = $this->projects->statuses($project);
        $status = WorkRefused::unlessNamed(static fn (): ProjectStatus => new ProjectStatus($project, $name, $type, \count($statuses)));

        return $this->unit->run(function () use ($status, $statuses, $position): ProjectStatus {
            $this->projects->add($status);
            Positions::place($statuses, $status, $position ?? \count($statuses));

            return $status;
        });
    }

    /**
     * @throws WorkRefused
     */
    public function rename(string $statusId, string $name): void
    {
        $status = $this->work->managedStatus($statusId);
        WorkRefused::unlessNamed(static fn () => $status->rename($name));
        $this->unit->run(static fn (): null => null);
    }

    /**
     * @throws WorkRefused when the project would lose its last open or done status
     */
    public function retype(string $statusId, StatusType $type): void
    {
        $status = $this->work->managedStatus($statusId);
        $after = array_map(
            static fn (ProjectStatus $other): StatusType => $other->id() === $status->id() ? $type : $other->type(),
            $this->projects->statuses($status->project()),
        );
        $this->assertKept($after);
        $this->unit->run(static fn () => $status->retype($type));
    }

    /**
     * @param int $position counted from 0 among the statuses of the project
     *
     * @throws WorkRefused
     */
    public function move(string $statusId, int $position): void
    {
        $status = $this->work->managedStatus($statusId);
        $this->unit->run(fn () => Positions::place($this->projects->statuses($status->project()), $status, $position));
    }

    /**
     * Removes a status; its tasks, subtasks included, move to the given status of the same project.
     *
     * @throws WorkRefused
     */
    public function remove(string $statusId, string $moveTasksToId): void
    {
        $status = $this->work->managedStatus($statusId);
        $target = $this->target($status->project(), $status, $moveTasksToId);
        $rest = array_values(array_filter($this->projects->statuses($status->project()), static fn (ProjectStatus $other): bool => $other->id() !== $status->id()));
        $this->assertKept(array_map(static fn (ProjectStatus $other): StatusType => $other->type(), $rest));

        $this->unit->run(function () use ($status, $target, $rest): void {
            $now = $this->clock->now();
            foreach ($this->tasks->inStatus($status) as $task) {
                $task->moveToStatus($target, $now);
            }
            Positions::renumber($rest);
            $this->projects->remove($status);
        });
    }

    private function target(Project $project, ProjectStatus $removed, string $targetId): ProjectStatus
    {
        $target = $this->projects->status($targetId);
        if (!$target instanceof ProjectStatus || $target->project()->id() !== $project->id() || $target->id() === $removed->id()) {
            throw new WorkRefused('target_status');
        }

        return $target;
    }

    /**
     * @param list<StatusType> $types of the statuses after the change
     */
    private function assertKept(array $types): void
    {
        if (!\in_array(StatusType::Open, $types, true)) {
            throw new WorkRefused('last_open');
        }
        if (!\in_array(StatusType::Done, $types, true)) {
            throw new WorkRefused('last_done');
        }
    }
}
