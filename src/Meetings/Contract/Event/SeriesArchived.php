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
 * A meeting series was archived: it takes no new meetings. The payload is thin (ADR 0030): read the series itself for the rest.
 */
#[DomainEvent('meetings.series.archived', version: 1)]
final readonly class SeriesArchived implements DomainEventPayload
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
