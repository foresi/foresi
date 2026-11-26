<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Timesheet\Support;

use Foresi\Identity\Domain\User;
use Foresi\Timesheet\Application\ManageTime;
use Foresi\Work\Domain\Project;

/**
 * Time logged on 18 November 2026 in a test of Work's API (WorkApiTestCase),
 * as the user, the owner unless told otherwise.
 */
trait LogsTime
{
    protected function logged(Project $project, ?string $taskId, int $minutes, ?User $as = null): string
    {
        return $this->as(fn (): string => $this->service(ManageTime::class)->log($project->id(), $taskId, new \DateTimeImmutable('2026-11-18'), $minutes)->id(), $as);
    }
}
