<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Contract\Event;

use Foresi\Deals\Contract\SalesRecords;
use Foresi\Shared\Contract\Event\DomainEvent;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventSubject;

/**
 * A deal was created. The payload is thin (ADR 0030): read the deal itself for
 * its title, client and lines.
 */
#[DomainEvent('deals.deal.created', version: 1)]
final readonly class DealCreated implements DomainEventPayload
{
    /**
     * @param string $status open, won or lost, after the change
     */
    public function __construct(
        public string $dealId,
        public string $status,
        public string $stageId,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject(SalesRecords::KIND, $this->dealId);
    }
}
