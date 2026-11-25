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
 * Something of a task changed: what it is called or says, who is responsible,
 * its due date, priority, section, status or labels. Each change records one;
 * a call that changes several fields records one for each.
 */
#[DomainEvent('work.task.updated', version: 1)]
final readonly class TaskUpdated implements DomainEventPayload
{
    /** The fields a change names. */
    public const string TITLE = 'title';
    public const string DESCRIPTION = 'description';
    public const string RESPONSIBLE = 'responsible';
    public const string DUE_ON = 'due_on';
    public const string PRIORITY = 'priority';
    public const string SECTION = 'section';
    public const string STATUS = 'status';
    public const string LABELS = 'labels';

    /**
     * @param list<string> $changed the fields that changed, of the constants above
     */
    public function __construct(
        public string $taskId,
        public string $projectId,
        public array $changed,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('task', $this->taskId);
    }
}
