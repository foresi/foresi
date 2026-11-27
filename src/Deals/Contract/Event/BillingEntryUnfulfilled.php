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
 * A planned invoice of a deal lost the invoice or recurring invoice that fulfilled it, unlinked by hand or because the document went to the trash, ended or was deleted; the forecast counts the entry again. The subject is the deal. Since module API 1.8.
 */
#[DomainEvent('deals.billing_entry.unfulfilled', version: 1)]
final readonly class BillingEntryUnfulfilled implements DomainEventPayload
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
