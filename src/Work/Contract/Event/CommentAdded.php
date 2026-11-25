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
 * Someone commented on a task. The subject is the task, whose comments the
 * subject URL leads to; the text is written by a person, so it is no fact.
 */
#[DomainEvent('work.comment.added', version: 1)]
final readonly class CommentAdded implements DomainEventPayload
{
    /**
     * @param bool $internal whether only the people of the organisation read it
     */
    public function __construct(
        public string $commentId,
        public string $taskId,
        public string $projectId,
        public bool $internal,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('task', $this->taskId);
    }
}
