<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Work\Domain\Section;

/**
 * The sections of the list of a project: adding, naming, ordering and removing
 * them. The tasks of a removed section stay in the project, in no section.
 */
final readonly class ManageSections
{
    public function __construct(
        private Projects $projects,
        private Tasks $tasks,
        private VisibleWork $work,
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
        $project = $this->work->project($projectId);
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
        $section = $this->work->section($sectionId);
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
        $section = $this->work->section($sectionId);
        $this->unit->run(fn () => Positions::place($this->projects->sections($section->project()), $section, $position));
    }

    /**
     * @throws WorkRefused
     */
    public function remove(string $sectionId): void
    {
        $section = $this->work->section($sectionId);
        $this->unit->run(function () use ($section): void {
            foreach ($this->tasks->inSection($section) as $task) {
                $task->moveToSection(null);
            }
            $rest = array_values(array_filter($this->projects->sections($section->project()), static fn (Section $other): bool => $other->id() !== $section->id()));
            Positions::renumber($rest);
            $this->projects->remove($section);
        });
    }
}
