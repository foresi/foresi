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
 * A project's time budget changed: a change was added or removed, or which
 * time uses the budget was chosen. Ids and what happened only; the hours and
 * the note are read from the budget (ADR 0030).
 */
#[DomainEvent('work.project_budget.changed', version: 1)]
final readonly class ProjectBudgetChanged implements DomainEventPayload
{
    public const string ADDED = 'added';
    public const string REMOVED = 'removed';
    public const string COUNTING = 'counting';

    /**
     * @param string|null $changeId the budget change added or removed; null when the counting changed
     * @param string      $change   added, removed or counting
     */
    public function __construct(
        public string $projectId,
        public ?string $changeId,
        public string $change,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('project', $this->projectId);
    }
}
