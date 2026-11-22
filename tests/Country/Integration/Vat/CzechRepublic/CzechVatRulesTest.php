<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Country\Integration\Vat\CzechRepublic;

use Foresi\Country\Integration\Vat\CzechRepublic\CzechVatRules;
use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Contract\VatRegistration;
use Foresi\Party\Contract\VatStretch;
use PHPUnit\Framework\TestCase;

/**
 * The Czech VAT periods, due dates, refund dates, payment details and the
 * warning about a quarter too early (ZDPH § 99a (3), § 101; DŘ § 33 (4)).
 */
final class CzechVatRulesTest extends TestCase
{
    private CzechVatRules $rules;
    private \DateTimeZone $zone;

    protected function setUp(): void
    {
        $this->rules = new CzechVatRules();
        $this->zone = new \DateTimeZone('Europe/Prague');
    }

    public function testTheCountryIsTheCzechRepublic(): void
    {
        self::assertSame('CZ', $this->rules->country());
    }

    public function testAMonthIsTheWholeCalendarMonth(): void
    {
        $period = $this->rules->periodOf($this->day('2026-11-12'), VatPeriodLength::Month);

        self::assertSame(['2026-11', '2026-11-01', '2026-11-30', VatPeriodLength::Month], [$period->key, $period->start->format('Y-m-d'), $period->end->format('Y-m-d'), $period->length]);
        self::assertSame('2027-02-28', $this->rules->periodOf($this->day('2027-02-01'), VatPeriodLength::Month)->end->format('Y-m-d'));
    }

    public function testAQuarterIsTheWholeCalendarQuarter(): void
    {
        foreach (['2026-01-01' => ['2026-Q1', '2026-01-01', '2026-03-31'], '2026-05-31' => ['2026-Q2', '2026-04-01', '2026-06-30'], '2026-09-30' => ['2026-Q3', '2026-07-01', '2026-09-30'], '2026-11-12' => ['2026-Q4', '2026-10-01', '2026-12-31']] as $day => $expected) {
            $period = $this->rules->periodOf($this->day($day), VatPeriodLength::Quarter);
            self::assertSame($expected, [$period->key, $period->start->format('Y-m-d'), $period->end->format('Y-m-d')], $day);
        }
    }

    public function testNovember2026IsDueOnMonday28December(): void
    {
        // The 25th is Christmas Day, the 26th St Stephen's Day on a Saturday, then Sunday the 27th.
        self::assertSame('2026-12-28', $this->dueOf('2026-11', VatPeriodLength::Month));
    }

    public function testMarch2027MovesFromSunday25ToMonday26April(): void
    {
        self::assertSame('2027-04-26', $this->dueOf('2027-03', VatPeriodLength::Month));
    }

    public function testAWorkingDayIsKept(): void
    {
        // 25 February 2027 is a Thursday.
        self::assertSame('2027-02-25', $this->dueOf('2027-01', VatPeriodLength::Month));
    }

    public function testQ42027IsDueOnTuesday25January2028(): void
    {
        self::assertSame('2028-01-25', $this->dueOf('2027-12', VatPeriodLength::Quarter));
    }

    public function testTheNovember2026RefundIsExpectedOn27January2027(): void
    {
        self::assertSame('2027-01-27', $this->rules->refundExpectedOn($this->rules->periodOf($this->day('2026-11-01'), VatPeriodLength::Month))->format('Y-m-d'));
    }

    public function testARefundOnADayOffMovesToTheNextWorkingDay(): void
    {
        // March 2027 is due on Monday 26 April; 30 days later is Wednesday 26 May, a working day.
        self::assertSame('2027-05-26', $this->rules->refundExpectedOn($this->rules->periodOf($this->day('2027-03-01'), VatPeriodLength::Month))->format('Y-m-d'));
        // August 2027 is due on Monday 27 September (the 25th is a Saturday); 30 days later is Wednesday 27 October, then 28 October is a holiday.
        self::assertSame('2027-10-27', $this->rules->refundExpectedOn($this->rules->periodOf($this->day('2027-08-01'), VatPeriodLength::Month))->format('Y-m-d'));
        // February 2027 is due on Thursday 25 March; 30 days later is Saturday 24 April, so Monday 26 April.
        self::assertSame('2027-04-26', $this->rules->refundExpectedOn($this->rules->periodOf($this->day('2027-02-01'), VatPeriodLength::Month))->format('Y-m-d'));
    }

    public function testAQuarterInTheRegistrationYearOrTheNextWarns(): void
    {
        self::assertSame(['quarter_not_allowed'], $this->rules->warnings([
            $this->stretch(VatRegistration::NotRegistered, null, null),
            $this->stretch(VatRegistration::Registered, VatPeriodLength::Quarter, '2026-11-12'),
        ]));
        self::assertSame(['quarter_not_allowed'], $this->rules->warnings([
            $this->stretch(VatRegistration::Registered, VatPeriodLength::Month, '2026-11-12'),
            $this->stretch(VatRegistration::Registered, VatPeriodLength::Quarter, '2027-10-01'),
        ]));
    }

    public function testAQuarterTwoYearsAfterTheRegistrationDoesNotWarn(): void
    {
        self::assertSame([], $this->rules->warnings([
            $this->stretch(VatRegistration::Registered, VatPeriodLength::Month, '2026-11-12'),
            $this->stretch(VatRegistration::Registered, VatPeriodLength::Quarter, '2028-01-01'),
        ]));
    }

    public function testARegistrationAgainStartsTheYearsAgain(): void
    {
        self::assertSame(['quarter_not_allowed'], $this->rules->warnings([
            $this->stretch(VatRegistration::Registered, VatPeriodLength::Month, '2020-01-01'),
            $this->stretch(VatRegistration::NotRegistered, null, '2026-01-01'),
            $this->stretch(VatRegistration::Registered, VatPeriodLength::Quarter, '2027-04-01'),
        ]));
    }

    public function testMonthsAndAnUnknownRegistrationDayDoNotWarn(): void
    {
        self::assertSame([], $this->rules->warnings([$this->stretch(VatRegistration::Registered, VatPeriodLength::Month, '2026-11-12')]));
        self::assertSame([], $this->rules->warnings([$this->stretch(VatRegistration::Registered, VatPeriodLength::Quarter, null)]));
        self::assertSame([], $this->rules->warnings([]));
    }

    public function testTheVariableSymbolIsTheVatIdDigits(): void
    {
        $payment = $this->rules->suggestedPayment('CZ12345678');

        self::assertSame('12345678', $payment->variableSymbol);
        self::assertSame('705-<number of your tax office>/0710', $payment->accountHint);
        self::assertStringStartsWith('https://', $payment->sourceUrl);
        self::assertSame('', $this->rules->suggestedPayment(null)->variableSymbol);
    }

    public function testTheOpenPointsAreUnverified(): void
    {
        self::assertSame(['refund_timing', 'refund_account', 'office_account_2026', 'late_deduction_limit', 'quarter_limit_2026', 'vat_by_tax_point_cash_basis'], $this->rules->unverified());
    }

    private function dueOf(string $month, VatPeriodLength $length): string
    {
        return $this->rules->dueOn($this->rules->periodOf($this->day($month.'-01'), $length))->format('Y-m-d');
    }

    private function day(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day, $this->zone);
    }

    private function stretch(VatRegistration $status, ?VatPeriodLength $length, ?string $from): VatStretch
    {
        return new VatStretch('stretch-'.($from ?? 'start'), $status, $length, null === $from ? null : $this->day($from));
    }
}
