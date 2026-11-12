<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Events\Fixtures\Valid\Contract\Event;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\DomainEventPayload;
use App\Shared\Domain\Event\EventSubject;

#[DomainEvent('invoicing.invoice.issued', version: 2)]
final readonly class InvoiceIssuedV2 implements DomainEventPayload
{
    public function __construct(
        public string $invoiceId,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('invoice', $this->invoiceId);
    }
}
