<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Foresi\Invoicing\Domain\Payment;
use Foresi\Invoicing\Domain\PaymentDirection;
use Foresi\Invoicing\Infrastructure\Mcp\GetPaymentTool;
use Foresi\Invoicing\Infrastructure\Mcp\SearchPaymentsTool;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Invoicing\Support\IssuesInvoices;
use Foresi\Tests\Shared\Infrastructure\Mcp\ReadToolTestCase;

/**
 * foresi_search_payments and foresi_get_payment answer what GET /api/v1/payments
 * and /api/v1/payments/{id} answer, narrowed by the same filters.
 */
final class PaymentToolsTest extends ReadToolTestCase
{
    use IssuesInvoices;

    public function testThePaymentIsAnsweredAsTheRestApiWritesIt(): void
    {
        $payment = $this->flushed(function (): Payment {
            $invoice = $this->invoice();

            return $this->payment('2026-11-12', [$invoice->id() => '1000'], note: 'Paid by bank');
        });

        $search = $this->call(SearchPaymentsTool::class);
        self::assertSame(['items' => $this->rest('/api/v1/payments'), 'next_cursor' => null], $search->data);
        self::assertSame([$payment->id()], self::itemIds($search));
        self::assertSame('1 payment, newest first; no more match.', $search->summary);

        $get = $this->call(GetPaymentTool::class, ['id' => $payment->id()]);
        self::assertSame($this->rest('/api/v1/payments/'.$payment->id()), $get->data);
        self::assertSame('Paid by bank', $get->data['note']);
        self::assertSame('The payment with the documents it is allocated to.', $get->summary);
    }

    public function testEveryFilterNarrowsTheSearch(): void
    {
        [$first, $other, $refund, $invoice, $cancelled] = $this->flushed(function (): array {
            $invoice = $this->invoice();
            $first = $this->payment('2026-11-01', [$invoice->id() => '1000']);
            $refund = $this->payment('2026-11-05', [$invoice->id() => '100'], direction: PaymentDirection::Refund);

            return [$first, $this->payment('2026-11-10', amount: '100'), $refund, $invoice, $this->payment(amount: '100', cancelled: true)];
        });

        self::assertSame([$refund->id(), $first->id()], self::itemIds($this->call(SearchPaymentsTool::class, ['invoice_id' => strtoupper($invoice->id())])));
        self::assertSame([$refund->id()], self::itemIds($this->call(SearchPaymentsTool::class, ['direction' => 'refund'])));
        self::assertSame([$other->id(), $first->id()], self::itemIds($this->call(SearchPaymentsTool::class, ['direction' => 'incoming'])));
        self::assertSame([$other->id(), $refund->id()], self::itemIds($this->call(SearchPaymentsTool::class, ['received_from' => '2026-11-05'])));
        self::assertSame([$refund->id(), $first->id()], self::itemIds($this->call(SearchPaymentsTool::class, ['received_to' => '2026-11-05'])));
        self::assertSame([$other->id(), $refund->id(), $first->id()], self::itemIds($this->call(SearchPaymentsTool::class, ['include_cancelled' => false])));
        self::assertSame([$cancelled->id(), $other->id(), $refund->id(), $first->id()], self::itemIds($this->call(SearchPaymentsTool::class, ['include_cancelled' => true])));
    }
}
