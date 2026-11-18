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
 * A recorded or cancelled payment made a payable document paid or overpaid. The payment is the one that caused it. Settlement by a credit note or an advance deduction does not record it yet.
 */
#[DomainEvent('invoicing.invoice.paid', version: 1)]
final readonly class InvoicePaid implements DomainEventPayload
{
    public function __construct(
        public string $invoiceId,
        public PaidState $state,
        public string $paymentId,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('invoice', $this->invoiceId);
    }
}
