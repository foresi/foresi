<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * Where the signed-in person may log time now: a task of an open project they
 * see, or a project open to them as a whole for time without a task; with what
 * the entry is by default.
 */
final readonly class TimeTarget
{
    public function __construct(
        public string $projectId,
        public string $projectName,
        public ?string $taskId,
        public ?string $taskTitle,
        public bool $billableByDefault,
    ) {
    }
}
