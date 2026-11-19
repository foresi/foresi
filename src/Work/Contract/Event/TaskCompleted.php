<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Contract\Event;

use Foresi\Shared\Domain\Event\DomainEvent;
use Foresi\Shared\Domain\Event\DomainEventPayload;
use Foresi\Shared\Domain\Event\EventSubject;

/**
 * A task moved into a finished status (of type done or closed) from one that
 * is not; work.task.updated tells the change of status too.
 */
#[DomainEvent('work.task.completed', version: 1)]
final readonly class TaskCompleted implements DomainEventPayload
{
    public function __construct(
        public string $taskId,
        public string $projectId,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('task', $this->taskId);
    }
}
