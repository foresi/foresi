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
 * A planned invoice of a deal was planned on a won deal, by hand or as the starting plan when it was won. The payload is thin (ADR 0030):
 * read the deal for its billing plan. The subject is the deal. Since module API 1.4.
 */
#[DomainEvent('sales.billing_entry.created', version: 1)]
final readonly class BillingEntryCreated implements DomainEventPayload
{
    /**
     * @param string $kind one_off or recurring
     */
    public function __construct(
        public string $entryId,
        public string $dealId,
        public string $kind,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject(SalesRecords::KIND, $this->dealId);
    }
}
