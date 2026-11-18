<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectStatus;
use Foresi\Work\Domain\StatusType;
use Foresi\Work\Domain\WorkflowTemplate;
use Psr\Clock\ClockInterface;

/**
 * The statuses of a project, the columns of its board. A project always keeps
 * a status of type open, where new tasks start, and one of type done, where
 * they finish; the tasks of a removed status move to another of the project.
 * A template applied later replaces all the statuses at once.
 */
final readonly class ManageWorkflow
{
    public function __construct(
        private Projects $projects,
        private Tasks $tasks,
        private VisibleWork $work,
        private StatusNames $statusNames,
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

    /**
     * Replaces the statuses of the project with those of the template, named
     * in the language of the person applying it. Every task, subtasks and the
     * trash included, moves to the first new status of the type it had; a
     * type the template lacks, such as closed in the simple one, goes to its
     * first done status. A task stays finished or unfinished, so its
     * completion date stays as it was.
     *
     * @throws WorkRefused
     */
    public function applyTemplate(string $projectId, WorkflowTemplate $template): void
    {
        $project = $this->work->managedProject($projectId);
        $old = $this->projects->statuses($project);

        $this->unit->run(function () use ($project, $template, $old): void {
            $new = [];
            foreach ($template->statuses() as $position => [$key, $type]) {
                $new[] = $status = new ProjectStatus($project, $this->statusNames->of($key), $type, $position);
                $this->projects->add($status);
            }
            $now = $this->clock->now();
            foreach ($old as $status) {
                $target = $this->firstOf($new, $status->type());
                foreach ($this->tasks->inStatus($status) as $task) {
                    $task->moveToStatus($target, $now);
                }
                $this->projects->remove($status);
            }
            $project->adoptWorkflow($template);
        });
    }

    /**
     * The first status of the type, or of the type closest to it: done for
     * closed, open for active, and the other way round.
     *
     * @param list<ProjectStatus> $statuses
     */
    private function firstOf(array $statuses, StatusType $type): ProjectStatus
    {
        $fallback = match ($type) {
            StatusType::Closed => StatusType::Done,
            StatusType::Done => StatusType::Closed,
            StatusType::Active => StatusType::Open,
            StatusType::Open => StatusType::Active,
        };
        foreach ([$type, $fallback] as $wanted) {
            foreach ($statuses as $status) {
                if ($status->type() === $wanted) {
                    return $status;
                }
            }
        }

        throw new \LogicException(\sprintf('The template has no status of type %s or %s.', $type->value, $fallback->value));
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
