<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Work\Domain\Section;
use App\Work\Domain\WorkShare;
use Psr\Clock\ClockInterface;

/**
 * The sections of the list of a project: adding, naming, ordering and removing
 * them. The tasks of a removed section stay in the project, in no section.
 * Those of a private section stay hidden as they were: each becomes private
 * and is shared with the people the section was shared with.
 */
final readonly class ManageSections
{
    public function __construct(
        private Projects $projects,
        private Tasks $tasks,
        private VisibleWork $work,
        private WorkShares $shares,
        private ClockInterface $clock,
        private WorkUnit $unit,
    ) {
    }

    /**
     * A new section at the end of the list.
     *
     * @throws WorkRefused
     */
    public function add(string $projectId, string $name): Section
    {
        $project = $this->work->managedProject($projectId);
        if ($project->isArchived()) {
            throw new WorkRefused('archived');
        }
        $section = WorkRefused::unlessNamed(fn (): Section => new Section($project, $name, \count($this->projects->sections($project))));

        return $this->unit->run(function () use ($section): Section {
            $this->projects->add($section);

            return $section;
        });
    }

    /**
     * @throws WorkRefused
     */
    public function rename(string $sectionId, string $name): void
    {
        $section = $this->work->managedSection($sectionId);
        WorkRefused::unlessNamed(static fn () => $section->rename($name));
        $this->unit->run(static fn (): null => null);
    }

    /**
     * @param int $position counted from 0 among the sections of the project
     *
     * @throws WorkRefused
     */
    public function move(string $sectionId, int $position): void
    {
        $section = $this->work->managedSection($sectionId);
        $this->unit->run(fn () => Positions::place($this->projects->sections($section->project()), $section, $position));
    }

    /**
     * @throws WorkRefused
     */
    public function remove(string $sectionId): void
    {
        $section = $this->work->managedSection($sectionId);
        $this->unit->run(function () use ($section): void {
            $shares = $this->shares->on($section);
            foreach ($this->tasks->inSection($section) as $task) {
                $task->moveToSection(null);
                if ($section->isPrivate() && !$task->isSubtask()) {
                    $task->makePrivate(true);
                    foreach ($shares as $share) {
                        if (!$this->shares->find($task, $share->membership()) instanceof WorkShare) {
                            $this->shares->add(WorkShare::of($task, $share->membership(), $this->clock->now()));
                        }
                    }
                }
            }
            foreach ($shares as $share) {
                $this->shares->remove($share);
            }
            $rest = array_values(array_filter($this->projects->sections($section->project()), static fn (Section $other): bool => $other->id() !== $section->id()));
            Positions::renumber($rest);
            $this->projects->remove($section);
        });
    }
}
