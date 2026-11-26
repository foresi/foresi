<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Contract\Event;

use Foresi\Shared\Contract\Event\DomainEvent;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventSubject;

/**
 * A meeting series was created. The payload is thin (ADR 0030): read the series itself for the rest.
 */
#[DomainEvent('meetings.series.created', version: 1)]
final readonly class SeriesCreated implements DomainEventPayload
{
    public function __construct(
        public string $seriesId,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('meeting_series', $this->seriesId);
    }
}
