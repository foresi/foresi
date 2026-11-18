<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Contract\Event;

use Foresi\Shared\Domain\Event\DomainEvent;
use Foresi\Shared\Domain\Event\DomainEventPayload;
use Foresi\Shared\Domain\Event\EventSubject;

/**
 * A document was issued. A correction names the document it corrects; the total is gross, a decimal string.
 */
#[DomainEvent('invoicing.invoice.issued', version: 1)]
final readonly class InvoiceIssued implements DomainEventPayload
{
    public function __construct(
        public string $invoiceId,
        public DocumentKindValue $kind,
        public string $number,
        public string $seriesId,
        public string $firmId,
        public string $customerId,
        public string $currency,
        public string $total,
        public string $issuedOn,
        public ?string $dueOn,
        public ?string $corrects,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('invoice', $this->invoiceId);
    }
}
