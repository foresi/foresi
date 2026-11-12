<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Events\Fixtures\Valid\Contract\Event;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\DomainEventPayload;
use App\Shared\Domain\Event\EventSubject;
use App\Tests\Events\Fixtures\Valid\Contract\Kind;

#[DomainEvent('invoicing.invoice.issued', version: 1)]
final readonly class InvoiceIssued implements DomainEventPayload
{
    /**
     * @param list<string> $lineCodes
     */
    public function __construct(
        public string $invoiceId,
        public int $lineCount,
        public bool $delivered,
        public ?string $dueOn,
        public Kind $kind,
        public array $lineCodes,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('invoice', $this->invoiceId);
    }
}
