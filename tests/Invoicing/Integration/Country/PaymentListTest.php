<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Foresi\Invoicing\Application\Query\PaymentList;
use Foresi\Invoicing\Application\Query\PaymentRow;
use Foresi\Invoicing\Application\Query\PaymentSlice;
use Foresi\Invoicing\Application\Query\PaymentSliceFilter;
use Foresi\Invoicing\Domain\PaymentDirection;
use Foresi\Invoicing\Domain\PaymentSource;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;

final class PaymentListTest extends PaymentReadTestCase
{
    public function testTheRowTellsWhatThePaymentIs(): void
    {
        $first = $this->invoice();
        $second = $this->invoice();
        $payment = $this->payment('2026-11-12', [$second->id() => '300', $first->id() => '700'], method: PaymentMethod::Card, source: PaymentSource::BankMovement, note: 'Paid by bank');

        $row = $this->list()->slice(new PaymentSliceFilter())->rows[0];

        self::assertSame($payment->id(), $row->id);
        self::assertSame('2026-11-12', $row->receivedOn->format('Y-m-d'));
        self::assertTrue(Money::of('1000', Currency::CZK)->equals($row->amount));
        self::assertSame(PaymentDirection::Incoming, $row->direction);
        self::assertSame(PaymentMethod::Card, $row->method);
        self::assertSame(PaymentSource::BankMovement, $row->source);
        self::assertSame('Paid by bank', $row->note);
        self::assertSame($payment->bankMovementId(), $row->bankMovementId);
        self::assertNotNull($row->bankMovementId);
        self::assertNull($row->cancelledAt);
        self::assertSame('2026-11-10 12:00:00', $row->createdAt->format('Y-m-d H:i:s'));
        self::assertSame([$first->id(), $second->id()], array_map(static fn (\Foresi\Invoicing\Application\Query\PaymentAllocationRow $allocation): string => $allocation->invoiceId, $row->allocations));
        self::assertSame([$first->number(), $second->number()], array_map(static fn (\Foresi\Invoicing\Application\Query\PaymentAllocationRow $allocation): string => $allocation->invoiceNumber, $row->allocations));
        self::assertSame([70000, 30000], array_map(static fn (\Foresi\Invoicing\Application\Query\PaymentAllocationRow $allocation): int => $allocation->amount->minor(), $row->allocations));
        self::assertSame([0, 0], array_map(static fn (\Foresi\Invoicing\Application\Query\PaymentAllocationRow $allocation): int => $allocation->settlement->minor(), $row->allocations));
    }

    public function testARefundHasPositiveAmountsAndTheDirection(): void
    {
        $invoice = $this->invoice();
        $this->payment('2026-11-10', [$invoice->id() => '1000']);
        $this->payment('2026-11-11', [$invoice->id() => '400'], direction: PaymentDirection::Refund);

        $row = $this->list()->slice(new PaymentSliceFilter(direction: PaymentDirection::Refund))->rows[0];

        self::assertSame(PaymentDirection::Refund, $row->direction);
        self::assertSame(40000, $row->amount->minor());
        self::assertSame(40000, $row->allocations[0]->amount->minor());
    }

    public function testCancelledPaymentsAreListedOnlyWhenAskedFor(): void
    {
        $invoice = $this->invoice();
        $this->payment(parts: [$invoice->id() => '100'], note: 'Live');
        $this->payment(note: 'Cancelled', amount: '100', cancelled: true);

        self::assertSame(['Live'], $this->notes($this->list()->slice(new PaymentSliceFilter())));
        self::assertSame(['Live'], $this->notes($this->list()->slice(new PaymentSliceFilter(includeCancelled: false))));
        $all = $this->list()->slice(new PaymentSliceFilter(includeCancelled: true));
        self::assertSame(['Cancelled', 'Live'], $this->notes($all));
        self::assertSame('2026-11-11 08:00:00', $all->rows[0]->cancelledAt?->format('Y-m-d H:i:s'));
        self::assertNull($all->rows[1]->cancelledAt);
    }

    public function testTheInvoiceNarrowsTheListToItsPayments(): void
    {
        $first = $this->invoice();
        $second = $this->invoice();
        $this->payment(parts: [$first->id() => '100'], note: 'Of the first');
        $this->payment(parts: [$second->id() => '100'], note: 'Of the second');
        $this->payment(parts: [$first->id() => '50', $second->id() => '50'], note: 'Of both');

        self::assertSame(['Of both', 'Of the first'], $this->notes($this->list()->slice(new PaymentSliceFilter(invoiceId: $first->id()))));
        self::assertSame(['Of both', 'Of the second'], $this->notes($this->list()->slice(new PaymentSliceFilter(invoiceId: $second->id()))));
        self::assertSame([], $this->notes($this->list()->slice(new PaymentSliceFilter(invoiceId: Id::generate()))));
    }

    public function testTheDirectionTheMethodAndTheSourceNarrowTheList(): void
    {
        $invoice = $this->invoice();
        $this->payment(parts: [$invoice->id() => '1000'], note: 'Incoming transfer');
        $this->payment(parts: [$invoice->id() => '100'], direction: PaymentDirection::Refund, method: PaymentMethod::Cash, note: 'Refund');
        $this->payment(parts: [$invoice->id() => '100'], method: PaymentMethod::Card, source: PaymentSource::BankMovement, note: 'Card by bank');

        self::assertSame(['Refund'], $this->notes($this->list()->slice(new PaymentSliceFilter(direction: PaymentDirection::Refund))));
        self::assertSame(['Card by bank', 'Incoming transfer'], $this->notes($this->list()->slice(new PaymentSliceFilter(direction: PaymentDirection::Incoming))));
        self::assertSame(['Refund'], $this->notes($this->list()->slice(new PaymentSliceFilter(method: PaymentMethod::Cash))));
        self::assertSame(['Card by bank'], $this->notes($this->list()->slice(new PaymentSliceFilter(source: PaymentSource::BankMovement))));
        self::assertSame(['Refund', 'Incoming transfer'], $this->notes($this->list()->slice(new PaymentSliceFilter(source: PaymentSource::Manual))));
        self::assertSame(['Incoming transfer'], $this->notes($this->list()->slice(new PaymentSliceFilter(direction: PaymentDirection::Incoming, source: PaymentSource::Manual, method: PaymentMethod::BankTransfer))));
    }

    private function list(): PaymentList
    {
        return new PaymentList($this->access(), $this->queries());
    }

    /**
     * @return list<string>
     */
    private function notes(PaymentSlice $slice): array
    {
        return array_map(static fn (PaymentRow $row): string => $row->note, $slice->rows);
    }
}
