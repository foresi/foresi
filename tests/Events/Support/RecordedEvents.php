<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Events\Support;

use App\Events\Infrastructure\Relay\EventRelay;
use App\Invoicing\Contract\Event\DocumentKindValue;
use App\Invoicing\Contract\Event\InvoiceIssued;
use App\Invoicing\Contract\Event\InvoicePaid;
use App\Invoicing\Contract\Event\InvoiceTrashed;
use App\Invoicing\Contract\Event\PaidState;
use App\Invoicing\Contract\Event\PaymentDirection;
use App\Invoicing\Contract\Event\PaymentRecorded;
use App\Shared\Application\Event\EventRecorder;
use App\Shared\Domain\Event\DomainEventPayload;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Doctrine\DBAL\Connection;

/**
 * Real contract events recorded in an organisation, as a use case records them,
 * and numbered by the relay, for the tests of the feed.
 */
final readonly class RecordedEvents
{
    public function __construct(
        private TenantContext $context,
        private Connection $connection,
        private EventRecorder $recorder,
        private EventRelay $relay,
    ) {
    }

    public function record(TenantId $tenant, DomainEventPayload $event): void
    {
        $this->context->switchTo($tenant);
        try {
            $this->connection->transactional(function () use ($event): void {
                $this->recorder->record($event);
            });
        } finally {
            $this->context->reset();
        }
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
