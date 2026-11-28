<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Vat\Integration\Country;

use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Vat\Support\VatTestCase;
use Foresi\Vat\Application\PeriodList;
use Foresi\Vat\Application\PeriodView;

/**
 * The VAT periods of the firm from its dated payer stretches, by the Czech
 * rules: the first period starts on the payer day, a stretch's end closes
 * the last one, and nothing is computed before a stated day.
 */
final class VatPeriodsTest extends VatTestCase
{
    public function testTheFirstPeriodStartsOnThePayerDay(): void
    {
        $this->martin();

        $list = $this->periods()->list(null, null);

        self::assertNull($list->reason);
        self::assertSame('CZ', $list->country);
        self::assertSame([null, '2027-11'], [$list->from, $list->to]);
        $first = $list->periods[0];
        self::assertSame(['2026-11', '2026-11-12', '2026-11-30', '2026-12-28', '2027-01-27'], $this->row($first));
        self::assertSame(VatPeriodLength::Month, $first->period->length);
        self::assertSame([0, 0, 0], [$first->output->minor(), $first->input->minor(), $first->result->minor()]);
        self::assertSame('CZK', $first->result->currency()->code());
        // Every month to the twelfth after this one, November 2027.
        self::assertCount(13, $list->periods);
        self::assertSame(['2027-11', '2027-11-01', '2027-11-30', '2027-12-27', '2028-01-26'], $this->row($list->periods[12]));
        self::assertSame(['deduction_before_registration'], $list->knownGaps);
        self::assertContains('refund_timing', $list->unverified);
        self::assertSame([], $list->warnings);
    }

    public function testNoPeriodBeforeAStatedDate(): void
    {
        // The firm is a payer and has no stretch yet: Party answers one undated stretch.
        $list = $this->periods()->list(null, null);

        self::assertSame(PeriodList::PAYER_DATE_MISSING, $list->reason);
        self::assertSame([], $list->periods);
        self::assertSame('CZ', $list->country);
    }

    public function testAnUndatedPayerBeforeADatedOneCountsFromTheDatedOne(): void
    {
        $this->stretch(VatStatus::Registered, VatPeriodLength::Month, null);
        $this->stretch(VatStatus::Registered, VatPeriodLength::Quarter, '2028-01-01');

        $list = $this->periods()->list(null, '2028-06');

        self::assertNull($list->reason);
        self::assertSame(['2028-Q1', '2028-Q2'], $this->keys($list));
    }

    public function testAFirmThatIsNoPayerHasNoPeriods(): void
    {
        $this->stretch(VatStatus::NotRegistered, null, null);
        $this->stretch(VatStatus::IdentifiedPerson, null, '2026-11-12');

        $list = $this->periods()->list(null, null);

        self::assertSame(PeriodList::NOT_A_PAYER, $list->reason);
        self::assertSame([], $list->periods);
    }

    public function testAStretchEndClosesTheLastPeriod(): void
    {
        $this->martin();
        $this->stretch(VatStatus::NotRegistered, null, '2027-03-15');

        $list = $this->periods()->list(null, null);

        self::assertSame(['2026-11', '2026-12', '2027-01', '2027-02', '2027-03'], $this->keys($list));
        self::assertSame(['2027-03', '2027-03-01', '2027-03-14', '2027-04-26', '2027-05-26'], $this->row($list->periods[4]));
    }

    public function testAPayerAgainStartsNewPeriods(): void
    {
        $this->martin();
        $this->stretch(VatStatus::NotRegistered, null, '2027-01-01');
        $this->stretch(VatStatus::Registered, VatPeriodLength::Month, '2027-03-10');

        $list = $this->periods()->list(null, '2027-04');

        self::assertSame(['2026-11', '2026-12', '2027-03', '2027-04'], $this->keys($list));
        self::assertSame('2027-03-10', $list->periods[2]->period->start->format('Y-m-d'));
    }

    public function testAQuarterlyPayerHasQuartersAndTheWarning(): void
    {
        $this->stretch(VatStatus::NotRegistered, null, null);
        $this->stretch(VatStatus::Registered, VatPeriodLength::Quarter, '2026-11-12');

        $list = $this->periods()->list(null, '2027-02');

        self::assertSame(['quarter_not_allowed'], $list->warnings);
        self::assertSame(['2026-Q4', '2027-Q1'], $this->keys($list));
        self::assertSame(['2026-Q4', '2026-11-12', '2026-12-31', '2027-01-25', '2027-02-24'], $this->row($list->periods[0]));
    }

    public function testALengthChangeOnAPeriodsFirstDayKeepsBothLengths(): void
    {
        $this->martin();
        $this->stretch(VatStatus::Registered, VatPeriodLength::Quarter, '2028-01-01');

        $list = $this->periods()->list('2027-11', '2028-04');

        self::assertSame(['2027-11', '2027-12', '2028-Q1', '2028-Q2'], $this->keys($list));
        self::assertSame(['2027-11', '2028-04'], [$list->from, $list->to]);
    }

    public function testTwoStretchesOfOneLengthInOnePeriodMakeOnePeriod(): void
    {
        $this->martin();
        $this->stretch(VatStatus::Registered, VatPeriodLength::Month, '2026-12-10');

        $list = $this->periods()->list(null, '2027-01');

        self::assertSame(['2026-11', '2026-12', '2027-01'], $this->keys($list));
        self::assertSame(['2026-12', '2026-12-01', '2026-12-31', '2027-01-25', '2027-02-24'], $this->row($list->periods[1]));
    }

    public function testFromAndToNarrowTheRangeAndTakeQuarters(): void
    {
        $this->martin();

        self::assertSame(['2027-01', '2027-02', '2027-03'], $this->keys($this->periods()->list('2027-Q1', '2027-Q1')));
        self::assertSame(['2026-11', '2026-12'], $this->keys($this->periods()->list('2026-01', '2026-12')));
        self::assertSame([], $this->periods()->list('2025-01', '2026-10')->periods);
        self::assertSame(['2027-04', '2027-05', '2027-06'], $this->keys($this->periods()->list(' 2027-q2 ', '2027-Q2')));
        self::assertSame(['2027-07', '2027-08', '2027-09'], $this->keys($this->periods()->list('2027-Q3', '2027-q3')));
        self::assertSame(['2027-01', '2027-03'], [$this->periods()->list(' 2027-01 ', '2027-03')->from, $this->periods()->list('2027-01', '2027-03')->to]);
    }

    public function testTheDayBoundaryFollowsPrague(): void
    {
        $this->martin();
        // 23:30 UTC on 30 November 2027 is already 1 December in Prague, so the range runs to December 2028.
        $this->clock->modify('2027-11-30 23:30:00');

        self::assertSame('2028-12', $this->periods()->list(null, null)->to);
        $this->clock->modify('2027-11-30 22:30:00');
        self::assertSame('2028-11', $this->periods()->list(null, null)->to);
    }

    public function testTheOwnerReadsToo(): void
    {
        $this->martin();
        $this->signIn($this->owner);

        self::assertCount(13, $this->periods()->list(null, null)->periods);
    }

    /**
     * Martin: not a payer from the start, a monthly payer from 12 November 2026.
     */
    private function martin(): void
    {
        $this->stretch(VatStatus::NotRegistered, null, null);
        $this->stretch(VatStatus::Registered, VatPeriodLength::Month, '2026-11-12');
    }

    /**
     * @return list<string>
     */
    private function keys(PeriodList $list): array
    {
        return array_map(static fn (PeriodView $view): string => $view->period->key, $list->periods);
    }

    /**
     * @return list<string>
     */
    private function row(PeriodView $view): array
    {
        return [$view->period->key, $view->period->start->format('Y-m-d'), $view->period->end->format('Y-m-d'), $view->dueOn->format('Y-m-d'), $view->refundOn->format('Y-m-d')];
    }
}
