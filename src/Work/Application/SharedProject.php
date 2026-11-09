<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * A project the person sees: all of it, or only the sections shared with them.
 */
final readonly class SharedProject
{
    /**
     * @param list<string> $sections the names of the sections shared with the person, by position; empty when the whole project is
     */
    public function __construct(public ProjectRow $project, public array $sections)
    {
    }
}
