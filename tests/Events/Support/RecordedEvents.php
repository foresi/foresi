<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Events\Support;

use Doctrine\DBAL\Connection;
use Foresi\Events\Infrastructure\Relay\EventRelay;
use Foresi\Invoicing\Contract\Event\DocumentKindValue;
use Foresi\Invoicing\Contract\Event\InvoiceIssued;
use Foresi\Invoicing\Contract\Event\InvoicePaid;
use Foresi\Invoicing\Contract\Event\InvoiceTrashed;
use Foresi\Invoicing\Contract\Event\PaidState;
use Foresi\Invoicing\Contract\Event\PaymentDirection;
use Foresi\Invoicing\Contract\Event\PaymentRecorded;
use Foresi\Shared\Application\Event\EventRecorder;
use Foresi\Shared\Domain\Event\DomainEventPayload;
use Foresi\Shared\Domain\Id;

/**
 * Real contract events recorded as a use case records them, and numbered by
 * the relay, for the tests of the feed.
 */
final readonly class RecordedEvents
{
    public function __construct(
        private Connection $connection,
        private EventRecorder $recorder,
        private EventRelay $relay,
    ) {
    }

    public function record(DomainEventPayload $event): void
    {
        $this->connection->transactional(function () use ($event): void {
            $this->recorder->record($event);
        });
    }

    public function number(): void
    {
        $this->relay->relayAll();
    }

    public static function issued(string $invoiceId): InvoiceIssued
    {
        return new InvoiceIssued($invoiceId, DocumentKindValue::Invoice, '2026-0001', Id::generate(), Id::generate(), Id::generate(), 'CZK', '1210.00', '2026-11-12', '2026-11-26', null);
    }

    public static function paid(string $invoiceId): InvoicePaid
    {
        return new InvoicePaid($invoiceId, PaidState::Paid, Id::generate());
    }

    public static function trashed(string $invoiceId): InvoiceTrashed
    {
        return new InvoiceTrashed($invoiceId, DocumentKindValue::Invoice);
    }

    public static function payment(string $paymentId, string $invoiceId): PaymentRecorded
    {
        return new PaymentRecorded($paymentId, Id::generate(), '1210.00', 'CZK', '2026-11-12', PaymentDirection::Incoming, 'manual', [$invoiceId]);
    }
}
