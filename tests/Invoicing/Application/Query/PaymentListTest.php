<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application\Query;

use App\Invoicing\Application\Query\PaymentList;
use App\Invoicing\Application\Query\PaymentRow;
use App\Invoicing\Application\Query\PaymentSlice;
use App\Invoicing\Application\Query\PaymentSliceFilter;
use App\Invoicing\Domain\PaymentDirection;
use App\Invoicing\Domain\PaymentSource;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Id;
use App\Shared\Domain\Money;

final class PaymentListTest extends PaymentReadTestCase
{
    public function testGuestsAndNobodyAreRefused(): void
    {
        $this->payment(amount: '100');

        $this->assertRefusedForGuestAndNobody(fn (): PaymentSlice => $this->list()->slice(new PaymentSliceFilter()));
    }

    public function testOwnersAndMembersRead(): void
    {
        $this->payment(note: 'Example', amount: '100');

        foreach ([$this->owner, $this->member] as $staff) {
            $this->signIn($staff);
            self::assertSame(['Example'], $this->notes($this->list()->slice(new PaymentSliceFilter())));
        }
    }

    public function testASliceIsNewestFirstAndKeepsOutOtherTenants(): void
    {
        $this->foreignPayment();
        $this->payment(note: 'First', amount: '100');
        $this->payment(note: 'Second', amount: '100');
        $this->payment(note: 'Third', amount: '100');

        self::assertSame(['Third', 'Second', 'First'], $this->notes($this->list()->slice(new PaymentSliceFilter())));
    }

    public function testTheRowTellsWhatThePaymentIs(): void
    {
        $first = $this->invoice();
        $second = $this->invoice();
        $payment = $this->payment('2026-11-12', [$second->id() => '300', $first->id() => '700'], method: PaymentMethod::Card, source: PaymentSource::BankMovement, note: 'Paid by bank');

        $row = $this->list()->slice(new PaymentSliceFilter())->rows[0];

        self::assertSame($payment->id(), $row->id);
        self::assertSame($this->firm->id(), $row->firmId);
        self::assertSame('Example Firm s.r.o.', $row->firmName);
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
        self::assertSame([$first->id(), $second->id()], array_map(static fn (\App\Invoicing\Application\Query\PaymentAllocationRow $allocation): string => $allocation->invoiceId, $row->allocations));
        self::assertSame([$first->number(), $second->number()], array_map(static fn (\App\Invoicing\Application\Query\PaymentAllocationRow $allocation): string => $allocation->invoiceNumber, $row->allocations));
        self::assertSame([70000, 30000], array_map(static fn (\App\Invoicing\Application\Query\PaymentAllocationRow $allocation): int => $allocation->amount->minor(), $row->allocations));
        self::assertSame([0, 0], array_map(static fn (\App\Invoicing\Application\Query\PaymentAllocationRow $allocation): int => $allocation->settlement->minor(), $row->allocations));
    }

    public function testAManualPaymentHasNoMovement(): void
    {
        $this->payment(amount: '100');

        self::assertNull($this->list()->slice(new PaymentSliceFilter())->rows[0]->bankMovementId);
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

    public function testTheFirmNarrowsTheList(): void
    {
        $other = $this->otherFirm();
        $this->payment(note: 'Of the first', amount: '100');
        $this->payment(firm: $other, note: 'Of the other', amount: '100');

        self::assertSame(['Of the other'], $this->notes($this->list()->slice(new PaymentSliceFilter(firmId: $other->id()))));
        self::assertSame(['Of the first'], $this->notes($this->list()->slice(new PaymentSliceFilter(firmId: $this->firm->id()))));
        self::assertSame([], $this->notes($this->list()->slice(new PaymentSliceFilter(firmId: Id::generate()))));
        self::assertSame('Other Firm s.r.o.', $this->list()->slice(new PaymentSliceFilter(firmId: $other->id()))->rows[0]->firmName);
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

    public function testTheDaysAreInclusive(): void
    {
        foreach (['2026-11-01', '2026-11-05', '2026-11-10'] as $day) {
            $this->payment($day, note: $day, amount: '100');
        }

        $from = new \DateTimeImmutable('2026-11-05');
        $to = new \DateTimeImmutable('2026-11-05');

        self::assertSame(['2026-11-10', '2026-11-05'], $this->notes($this->list()->slice(new PaymentSliceFilter(receivedFrom: $from))));
        self::assertSame(['2026-11-05', '2026-11-01'], $this->notes($this->list()->slice(new PaymentSliceFilter(receivedTo: $to))));
        self::assertSame(['2026-11-05'], $this->notes($this->list()->slice(new PaymentSliceFilter(receivedFrom: $from, receivedTo: $to))));
        self::assertSame([], $this->notes($this->list()->slice(new PaymentSliceFilter(receivedFrom: new \DateTimeImmutable('2026-11-11')))));
        self::assertSame([], $this->notes($this->list()->slice(new PaymentSliceFilter(receivedTo: new \DateTimeImmutable('2026-10-31')))));
    }

    public function testTheCursorExcludesItsOwnRowAndOnlyOlderOnesFollow(): void
    {
        $first = $this->payment(note: 'First', amount: '100');
        $second = $this->payment(note: 'Second', amount: '100');
        $third = $this->payment(note: 'Third', amount: '100');

        self::assertSame(['Second', 'First'], $this->notes($this->list()->slice(new PaymentSliceFilter(), $third->id())));
        self::assertSame(['First'], $this->notes($this->list()->slice(new PaymentSliceFilter(), $second->id())));
        self::assertSame([], $this->notes($this->list()->slice(new PaymentSliceFilter(), $first->id())));
    }

    public function testAFurtherSliceIsKnownByOneRowMoreThanTheLimit(): void
    {
        $this->payment(note: 'First', amount: '100');
        $this->payment(note: 'Second', amount: '100');
        $this->payment(note: 'Third', amount: '100');

        $slice = $this->list()->slice(new PaymentSliceFilter(), null, 2);
        self::assertSame(['Third', 'Second'], $this->notes($slice));
        self::assertTrue($slice->hasMore);
        self::assertSame($slice->rows[1]->id, $slice->lastId());

        $next = $this->list()->slice(new PaymentSliceFilter(), $slice->lastId(), 2);
        self::assertSame(['First'], $this->notes($next));
        self::assertFalse($next->hasMore);

        $exact = $this->list()->slice(new PaymentSliceFilter(), null, 3);
        self::assertCount(3, $exact->rows);
        self::assertFalse($exact->hasMore, 'A slice that ends with the last row has no more.');
    }

    public function testAnEmptySliceHasNoLastId(): void
    {
        $slice = $this->list()->slice(new PaymentSliceFilter());

        self::assertSame([], $slice->rows);
        self::assertNull($slice->lastId());
        self::assertFalse($slice->hasMore);
    }

    public function testACursorThatIsNoIdListsNothingAndAsksNoOne(): void
    {
        $queries = $this->spy();
        $list = new PaymentList($this->access(), $queries);

        $slice = $list->slice(new PaymentSliceFilter(), 'not-an-id');

        self::assertSame([], $slice->rows);
        self::assertFalse($slice->hasMore);
        self::assertSame([], $queries->limits);
        $list->slice(new PaymentSliceFilter(), Id::generate());
        self::assertSame([26], $queries->limits);
    }

    public function testTheLimitIsKeptBetweenOneAndTheMaximum(): void
    {
        $queries = $this->spy();
        $list = new PaymentList($this->access(), $queries);

        $list->slice(new PaymentSliceFilter());
        $list->slice(new PaymentSliceFilter(), null, 0);
        $list->slice(new PaymentSliceFilter(), null, -5);
        $list->slice(new PaymentSliceFilter(), null, 1);
        $list->slice(new PaymentSliceFilter(), null, 100);
        $list->slice(new PaymentSliceFilter(), null, 101);
        $list->slice(new PaymentSliceFilter(), null, 5000);

        self::assertSame([26, 2, 2, 2, 101, 101, 101], $queries->limits);
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

    private function spy(): PaymentLimitSpy
    {
        return new PaymentLimitSpy();
    }
}
