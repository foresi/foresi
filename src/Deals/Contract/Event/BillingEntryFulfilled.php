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
 * A planned invoice of a deal was linked to the invoice or recurring invoice that fulfils it; the forecast counts that document instead. The subject is the deal. Since module API 1.8.
 */
#[DomainEvent('deals.billing_entry.fulfilled', version: 1)]
final readonly class BillingEntryFulfilled implements DomainEventPayload
{
    /**
     * @param string $kind         one_off or recurring
     * @param string $documentType invoice or recurring_invoice
     */
    public function __construct(
        public string $entryId,
        public string $dealId,
        public string $kind,
        public string $documentType,
        public string $documentId,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject(SalesRecords::KIND, $this->dealId);
    }
}
