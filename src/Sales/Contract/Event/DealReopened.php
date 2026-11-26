<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Contract\Event;

use Foresi\Sales\Contract\SalesRecords;
use Foresi\Shared\Contract\Event\DomainEvent;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventSubject;

/**
 * A won or lost deal was opened again. The payload is thin (ADR 0030): read
 * the deal itself for its title, client and lines.
 */
#[DomainEvent('sales.deal.reopened', version: 1)]
final readonly class DealReopened implements DomainEventPayload
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
