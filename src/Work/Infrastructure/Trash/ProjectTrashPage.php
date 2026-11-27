<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Trash;

use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Contract\ProjectTrashSection;
use Foresi\Work\Contract\ProjectTrashSources;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The trash of a project as its pages show it: the tasks deleted from it and
 * what other modules keep of it there (ProjectTrashSources), counted together
 * for the link of the header.
 */
final readonly class ProjectTrashPage
{
    /**
     * @param iterable<ProjectTrashSources> $sources
     */
    public function __construct(
        private WorkQueries $queries,
        #[AutowireIterator(ProjectTrashSources::TAG)]
        private iterable $sources = [],
    ) {
    }

    public function count(string $projectId): int
    {
        $count = $this->queries->trashCount($projectId);
        foreach ($this->sources as $source) {
            $count += $source->count($projectId);
        }

        return $count;
    }

    /**
     * @return list<ProjectTrashSection> by weight, those with nothing in the trash left out
     */
    public function sections(string $projectId): array
    {
        $sections = [];
        foreach ($this->sources as $source) {
            $section = $source->section($projectId);
            if ($section instanceof ProjectTrashSection) {
                $sections[] = $section;
            }
        }
        usort($sections, static fn (ProjectTrashSection $a, ProjectTrashSection $b): int => $a->weight <=> $b->weight);

        return $sections;
    }
}
