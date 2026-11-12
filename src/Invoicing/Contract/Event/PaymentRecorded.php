<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Contract\Event;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\DomainEventPayload;
use App\Shared\Domain\Event\EventSubject;

/**
 * A payment was recorded and split over the invoices it pays. The source is manual or bank_movement.
 */
#[DomainEvent('invoicing.payment.recorded', version: 1)]
final readonly class PaymentRecorded implements DomainEventPayload
{
    /**
     * @param list<string> $invoiceIds
     */
    public function __construct(
        public string $paymentId,
        public string $firmId,
        public string $amount,
        public string $currency,
        public string $receivedOn,
        public PaymentDirection $direction,
        public string $source,
        public array $invoiceIds,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('payment', $this->paymentId);
    }
}
