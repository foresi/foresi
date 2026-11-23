<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application\Query;

use Foresi\Invoicing\Application\Query\PaymentList;
use Foresi\Invoicing\Application\Query\PaymentRow;
use Foresi\Invoicing\Application\Query\PaymentSlice;
use Foresi\Invoicing\Application\Query\PaymentSliceFilter;
use Foresi\Shared\Domain\Id;

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

    public function testASliceIsNewestFirst(): void
    {
        $this->payment(note: 'First', amount: '100');
        $this->payment(note: 'Second', amount: '100');
        $this->payment(note: 'Third', amount: '100');

        self::assertSame(['Third', 'Second', 'First'], $this->notes($this->list()->slice(new PaymentSliceFilter())));
    }

    public function testAManualPaymentHasNoMovement(): void
    {
        $this->payment(amount: '100');

        self::assertNull($this->list()->slice(new PaymentSliceFilter())->rows[0]->bankMovementId);
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
