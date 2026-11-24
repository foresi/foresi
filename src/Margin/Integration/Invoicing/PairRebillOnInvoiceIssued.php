<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Integration\Invoicing;

use Foresi\Invoicing\Contract\Event\InvoiceIssued;
use Foresi\Margin\Application\PairRebillOnIssue;
use Foresi\Shared\Application\Event\DomainEventListener;
use Foresi\Shared\Application\Event\RecordedEvent;

/**
 * Pairs the lines of an issued invoice with the planned occurrences they
 * rebill. An asynchronous listener of Invoicing's event (ADR 0030), run after
 * the invoice is committed, so it reads the stored invoice; a delivery that
 * comes twice leaves the same pairings. The class name is the listener's key;
 * keep it.
 */
final readonly class PairRebillOnInvoiceIssued implements DomainEventListener
{
    public const string EVENT = 'invoicing.invoice.issued';

    public function __construct(private PairRebillOnIssue $pairing)
    {
    }

    public function listensTo(): string
    {
        return self::EVENT;
    }

    public function __invoke(RecordedEvent $event): void
    {
        if (!$event->payload instanceof InvoiceIssued) {
            return;
        }
        // The relay runs each delivery in a transaction of its own and flushes it (DeliveryWork).
        $this->pairing->pair($event->payload->invoiceId);
    }
}
