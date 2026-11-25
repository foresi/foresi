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
 * A deal came back from the trash. The payload is thin (ADR 0030): read the
 * deal itself for its title, client and lines.
 */
#[DomainEvent('sales.deal.restored', version: 1)]
final readonly class DealRestored implements DomainEventPayload
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
