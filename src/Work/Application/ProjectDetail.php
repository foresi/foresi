<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

/**
 * One project as the API reads it: its row, what it is about, its workflow
 * and the sections the person sees.
 */
final readonly class ProjectDetail
{
    /**
     * @param list<StatusChoice> $statuses by position
     * @param list<Choice>       $sections the sections the person sees, by position
     */
    public function __construct(
        public ProjectRow $row,
        public string $description,
        public bool $billableByDefault,
        public array $statuses,
        public array $sections,
    ) {
    }
}
