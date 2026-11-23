<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Foresi\Invoicing\Domain\PaymentDirection;
use Foresi\Shared\Infrastructure\Api\Cursor;
use Foresi\Tests\Invoicing\Support\IssuesInvoices;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;

/**
 * GET /api/v1/payments and /api/v1/payments/{id}: the payments of the token's
 * organisation with the invoices they pay, narrowed by the query string and read
 * by cursor.
 */
final class PaymentApiTest extends ApiTestCase
{
    use IssuesInvoices;

    public function testAPaymentIsWrittenWithItsAllocationsInTheListAndAlone(): void
    {
        [$invoice, $payment] = $this->flushed(function (): array {
            $invoice = $this->invoice();

            return [$invoice, $this->payment('2026-11-12', [$invoice->id() => '1000'], note: 'Paid by bank')];
        });
        $expected = [
            'id' => $payment->id(),
            'firm' => ['id' => $this->firmId, 'name' => 'Example Firm s.r.o.'],
            'received_on' => '2026-11-12',
            'amount' => ['amount_minor' => 100000, 'currency' => 'CZK'],
            'direction' => 'incoming',
            'method' => 'bank_transfer',
            'source' => 'manual',
            'note' => 'Paid by bank',
            'bank_movement_id' => null,
            'cancelled_at' => null,
            'created_at' => new \DateTimeImmutable('2026-11-10 12:00:00')->format(\DATE_ATOM),
            'allocations' => [[
                'invoice' => ['id' => $invoice->id(), 'number' => $invoice->number()],
                'amount' => ['amount_minor' => 100000, 'currency' => 'CZK'],
                'settlement' => ['amount_minor' => 0, 'currency' => 'CZK'],
            ]],
        ];

        $this->get('/api/v1/payments');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertSame(['data' => [$expected], 'next_cursor' => null], $this->json());

        $this->get('/api/v1/payments/'.$payment->id());

        self::assertResponseIsSuccessful();
        self::assertSame(['data' => $expected], $this->json());
    }

    public function testTheInvoiceAndTheDirectionNarrowTheList(): void
    {
        [$first, $other, $refund, $invoice] = $this->flushed(function (): array {
            $invoice = $this->invoice();
            $first = $this->payment(parts: [$invoice->id() => '1000']);
            $refund = $this->payment(parts: [$invoice->id() => '100'], direction: PaymentDirection::Refund);

            return [$first, $this->payment(amount: '100'), $refund, $invoice];
        });

        $this->get('/api/v1/payments', ['invoice_id' => $invoice->id()]);
        self::assertSame([$refund->id(), $first->id()], $this->ids());
        $this->get('/api/v1/payments', ['invoice_id' => strtoupper($invoice->id())]);
        self::assertSame([$refund->id(), $first->id()], $this->ids(), 'An id is read whatever its case.');
        $this->get('/api/v1/payments', ['direction' => 'refund']);
        self::assertSame([$refund->id()], $this->ids());
        $this->get('/api/v1/payments', ['direction' => 'incoming']);
        self::assertSame([$other->id(), $first->id()], $this->ids());
    }
}
