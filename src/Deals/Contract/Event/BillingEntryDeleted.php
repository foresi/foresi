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
 * A planned invoice of a deal was removed from the billing plan of its deal. The payload is thin (ADR 0030):
 * read the deal for its billing plan. The subject is the deal. Since module API 1.8.
 */
#[DomainEvent('deals.billing_entry.deleted', version: 1)]
final readonly class BillingEntryDeleted implements DomainEventPayload
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
