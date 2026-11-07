<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * Something to pick by id: a section, a person, a parent task.
 */
final readonly class Choice
{
    public function __construct(public string $id, public string $name)
    {
    }
}
