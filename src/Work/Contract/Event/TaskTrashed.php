<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Contract\Event;

use Foresi\Shared\Contract\Event\DomainEvent;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventSubject;

/**
 * A task went to the trash with its subtasks, which record no event of their
 * own. It is purged after 30 days unless restored.
 */
#[DomainEvent('work.task.trashed', version: 1)]
final readonly class TaskTrashed implements DomainEventPayload
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
