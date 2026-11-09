<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Work\Domain\Project;
use App\Work\Domain\ProjectVisibility;
use App\Work\Domain\Section;
use App\Work\Domain\Task;
use App\Work\Domain\WorkShare;

/**
 * What of the work of the organisation one person sees: the rule itself, in
 * PHP for a command and as plain data for a query, which applies it in its own
 * language. WorkVisibility decides whose scope it is.
 *
 * An owner sees everything. Anyone else sees what is open to them:
 *
 * - a project is open to a team member when it is open to the organisation or
 *   shared with them, and to a guest only when it is shared with them;
 * - in an open project, every section and task that is not private;
 * - a section shared with them, private or not, and its tasks that are not private;
 * - a task shared with them, private or not, wherever it is;
 * - a task in a private section only with the section or a share of its own;
 * - a subtask with its task, unless it is private itself and not shared.
 *
 * A project is seen, its page and list included, when it is open or one of
 * its sections is shared; the list then shows only what is seen in it. A task
 * shared on its own shows the name of its project for orientation and nothing
 * else of it: no sibling task, no section.
 */
final readonly class WorkScope
{
    /**
     * @param list<string> $projectIds        the projects shared with the person
     * @param list<string> $sectionIds        the sections shared with the person
     * @param list<string> $taskIds           the tasks shared with the person
     * @param list<string> $sectionProjectIds the projects of those sections
     * @param list<string> $taskProjectIds    the projects of those tasks
     */
    private function __construct(
        private bool $everything,
        private bool $organisation,
        private array $projectIds = [],
        private array $sectionIds = [],
        private array $taskIds = [],
        private array $sectionProjectIds = [],
        private array $taskProjectIds = [],
    ) {
    }

    public static function everything(): self
    {
        return new self(true, true);
    }

    public static function nothing(): self
    {
        return new self(false, false);
    }

    /**
     * What the shares of a person open to them, besides the projects open to
     * the organisation when they are a team member.
     *
     * @param list<WorkShare> $shares
     */
    public static function shared(bool $organisation, array $shares): self
    {
        $projects = $sections = $tasks = $sectionProjects = $taskProjects = [];
        foreach ($shares as $share) {
            $project = $share->project();
            $section = $share->section();
            $task = $share->task();
            if ($project instanceof Project) {
                $projects[$project->id()] = true;
            } elseif ($section instanceof Section) {
                $sections[$section->id()] = true;
                $sectionProjects[$section->project()->id()] = true;
            } elseif ($task instanceof Task) {
                $tasks[$task->id()] = true;
                $taskProjects[$task->project()->id()] = true;
            }
        }

        return new self(false, $organisation, array_keys($projects), array_keys($sections), array_keys($tasks), array_keys($sectionProjects), array_keys($taskProjects));
    }

    public function isEverything(): bool
    {
        return $this->everything;
    }

    /**
     * Whether the projects open to the organisation are open to the person.
     */
    public function seesOrganisation(): bool
    {
        return $this->organisation;
    }

    /**
     * @return list<string>
     */
    public function projectIds(): array
    {
        return $this->projectIds;
    }

    /**
     * @return list<string>
     */
    public function sectionIds(): array
    {
        return $this->sectionIds;
    }

    /**
     * @return list<string>
     */
    public function taskIds(): array
    {
        return $this->taskIds;
    }

    /**
     * The projects seen for a section shared in them, though not open.
     *
     * @return list<string>
     */
    public function sectionProjectIds(): array
    {
        return $this->sectionProjectIds;
    }

    /**
     * The projects whose name is known for a task shared in them alone.
     *
     * @return list<string>
     */
    public function taskProjectIds(): array
    {
        return $this->taskProjectIds;
    }

    /**
     * Whether everything in the project that is not private is seen.
     */
    public function opens(Project $project): bool
    {
        return $this->everything
            || ($this->organisation && ProjectVisibility::Organisation === $project->visibility())
            || \in_array($project->id(), $this->projectIds, true);
    }

    public function seesProject(Project $project): bool
    {
        return $this->opens($project) || \in_array($project->id(), $this->sectionProjectIds, true);
    }

    /**
     * Whether the person may know the project exists and its name, for a task
     * of it they see, though not the project itself.
     */
    public function knowsProject(Project $project): bool
    {
        return $this->seesProject($project) || \in_array($project->id(), $this->taskProjectIds, true);
    }

    public function seesSection(Section $section): bool
    {
        return $this->everything
            || \in_array($section->id(), $this->sectionIds, true)
            || (!$section->isPrivate() && $this->opens($section->project()));
    }

    public function seesTask(Task $task): bool
    {
        if ($this->everything || \in_array($task->id(), $this->taskIds, true)) {
            return true;
        }
        if ($task->isPrivate()) {
            return false;
        }
        $parent = $task->parent();
        if ($parent instanceof Task) {
            return $this->seesTask($parent);
        }
        $section = $task->section();

        return $section instanceof Section ? $this->seesSection($section) : $this->opens($task->project());
    }

    /**
     * Whether a new task may go into the project, in the section or in none:
     * where the person sees the section, or the whole project for none.
     */
    public function allowsTaskIn(Project $project, ?Section $section): bool
    {
        if (!$section instanceof Section) {
            return $this->opens($project);
        }

        return $section->project()->id() === $project->id() && $this->seesSection($section);
    }
}
