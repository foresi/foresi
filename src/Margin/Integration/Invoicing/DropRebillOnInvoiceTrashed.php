<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Integration\Invoicing;

use Foresi\Invoicing\Contract\Event\InvoiceTrashed;
use Foresi\Margin\Application\DropRebillOnTrash;
use Foresi\Shared\Contract\Event\DomainEventListener;
use Foresi\Shared\Contract\Event\RecordedEvent;

/**
 * An invoice in the trash rebills nothing: its rebill pairings are removed. An
 * asynchronous listener of Invoicing's event (ADR 0030), idempotent; the relay
 * flushes its unit of work. The class name is the listener's key; keep it.
 */
final readonly class DropRebillOnInvoiceTrashed implements DomainEventListener
{
    public const string EVENT = 'invoicing.invoice.trashed';

    public function __construct(private DropRebillOnTrash $drop)
    {
    }

    public function listensTo(): string
    {
        return self::EVENT;
    }

    public function __invoke(RecordedEvent $event): void
    {
        if ($event->payload instanceof InvoiceTrashed) {
            $this->drop->drop($event->payload->invoiceId);
        }
    }
}
