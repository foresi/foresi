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
 * A task was added to a project, by a person or a client of the API. Titles
 * and descriptions are written by people, so they are no facts; the feed
 * shows the event only to those who see the task.
 */
#[DomainEvent('work.task.created', version: 1)]
final readonly class TaskCreated implements DomainEventPayload
{
    /**
     * @param string|null $parentId the task it is a subtask of
     */
    public function __construct(
        public string $taskId,
        public string $projectId,
        public ?string $parentId,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('task', $this->taskId);
    }
}
