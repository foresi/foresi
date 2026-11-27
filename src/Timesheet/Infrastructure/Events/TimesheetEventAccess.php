<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Infrastructure\Events;

use Foresi\Shared\Application\Event\EventReadAccess;
use Foresi\Shared\Application\Event\EventSubjectAccess;
use Foresi\Shared\Contract\Event\EventSubject;
use Foresi\Work\Infrastructure\Events\WorkEventAccess;

/**
 * The events of time, such as a change of a project's time budget, are read
 * as the events of the project they are about: by the people of the
 * organisation who see the project (Work's rule).
 */
final readonly class TimesheetEventAccess implements EventReadAccess, EventSubjectAccess
{
    public function __construct(private WorkEventAccess $work)
    {
    }

    public function module(): string
    {
        return 'timesheet';
    }

    public function mayRead(): bool
    {
        return $this->work->mayRead();
    }

    public function mayUserRead(string $userId): bool
    {
        return $this->work->mayUserRead($userId);
    }

    public function sees(EventSubject $subject): bool
    {
        return $this->work->sees($subject);
    }

    public function userSees(string $userId, EventSubject $subject): bool
    {
        return $this->work->userSees($userId, $subject);
    }
}
