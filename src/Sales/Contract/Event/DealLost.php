<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Contract\Event;

use Foresi\Sales\Contract\SalesRecords;
use Foresi\Shared\Domain\Event\DomainEvent;
use Foresi\Shared\Domain\Event\DomainEventPayload;
use Foresi\Shared\Domain\Event\EventSubject;

/**
 * A deal was lost, for a reason from the list or none; the note is not in the
 * event. The payload is thin (ADR 0030): read the deal itself for its title,
 * client and lines.
 */
#[DomainEvent('sales.deal.lost', version: 1)]
final readonly class DealLost implements DomainEventPayload
{
    /**
     * @param string $status open, won or lost, after the change
     */
    public function __construct(
        public string $dealId,
        public string $status,
        public string $stageId,
        public ?string $lostReasonId,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject(SalesRecords::KIND, $this->dealId);
    }
}
