<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Contract\Event;

use Foresi\Shared\Domain\Event\DomainEvent;
use Foresi\Shared\Domain\Event\DomainEventPayload;
use Foresi\Shared\Domain\Event\EventSubject;

/**
 * An activity was logged or planned. Ids and the type key only, no subject or notes (ADR 0030).
 */
#[DomainEvent('activities.activity.created', version: 1)]
final readonly class ActivityCreated implements DomainEventPayload
{
    /**
     * @param string       $typeKey     the key of the activity's type, such as "meeting"
     * @param list<string> $links       the records it is about, each as "kind:id", such as "contact:0199..."
     * @param string       $ownerUserId the person it belongs to
     */
    public function __construct(
        public string $activityId,
        public string $typeKey,
        public array $links,
        public string $ownerUserId,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('activity', $this->activityId);
    }
}
