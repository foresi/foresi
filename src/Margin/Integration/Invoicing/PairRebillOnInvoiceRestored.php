<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Integration\Invoicing;

use Foresi\Invoicing\Contract\Event\InvoiceRestored;
use Foresi\Margin\Application\PairRebillOnIssue;
use Foresi\Shared\Application\Event\DomainEventListener;
use Foresi\Shared\Application\Event\RecordedEvent;

/**
 * An invoice that comes back from the trash rebills again: its lines are
 * paired as they are when it is issued. An asynchronous listener of Invoicing's
 * event (ADR 0030), idempotent; the relay flushes its unit of work. The class
 * name is the listener's key; keep it.
 */
final readonly class PairRebillOnInvoiceRestored implements DomainEventListener
{
    public const string EVENT = 'invoicing.invoice.restored';

    public function __construct(private PairRebillOnIssue $pairing)
    {
    }

    public function listensTo(): string
    {
        return self::EVENT;
    }

    public function __invoke(RecordedEvent $event): void
    {
        if ($event->payload instanceof InvoiceRestored) {
            $this->pairing->pair($event->payload->invoiceId);
        }
    }
}
