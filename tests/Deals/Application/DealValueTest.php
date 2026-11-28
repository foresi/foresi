<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Deals\Application;

use Foresi\Catalog\Contract\BillingKind;
use Foresi\Deals\Application\DealValue;
use Foresi\Deals\Application\DealValues;
use Foresi\Deals\Domain\DealLine;
use Foresi\Deals\Domain\DealLineTerms;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;
use PHPUnit\Framework\TestCase;

/**
 * The value of a deal from its lines: one-off and monthly apart, a total per
 * currency, each line rounded half-up to minor units before the sum.
 */
final class DealValueTest extends TestCase
{
    public function testOneOffAndMonthlyApart(): void
    {
        $value = DealValues::of([
            $this->line('Roof audit', '12000.00'),
            $this->line('Maintenance', '1500.00', unit: RecurrenceUnit::Month),
            $this->line('Inspection', '333.33', '3', RecurrenceUnit::Month, interval: 2),
        ]);

        self::assertTrue($value->hasValue());
        self::assertSame(['CZK' => 1_200_000], $this->minor($value->oneOff));
        // 1500.00 a month, plus 3 × 333.33 every two months: 499.995 a month, rounded half-up to 500.00.
        self::assertSame(['CZK' => 200_000], $this->minor($value->monthly));
        self::assertFalse($value->approximate);
    }

    public function testYearlyLineDividedByTwelve(): void
    {
        $value = DealValues::of([
            $this->line('Licence', '1000.00', unit: RecurrenceUnit::Year),
            $this->line('Audit every two years', '2400.00', unit: RecurrenceUnit::Year, interval: 2),
        ]);

        // 1000 / 12 = 83.333… → 83.33; 2400 / 24 = 100.00.
        self::assertSame(['CZK' => 18_333], $this->minor($value->monthly));
        self::assertSame([], $value->oneOff);
        self::assertFalse($value->approximate);
    }

    public function testWeeklyLineMarkedApproximate(): void
    {
        $weekly = DealValues::of([$this->line('Cleaning', '100.00', unit: RecurrenceUnit::Week)]);
        // 100 × 52 / 12 = 433.333… → 433.33.
        self::assertSame(['CZK' => 43_333], $this->minor($weekly->monthly));
        self::assertTrue($weekly->approximate);

        $daily = DealValues::of([$this->line('Hosting', '10.00', unit: RecurrenceUnit::Day, interval: 2)]);
        // 10 × 365 / (12 × 2) = 152.083… → 152.08.
        self::assertSame(['CZK' => 15_208], $this->minor($daily->monthly));
        self::assertTrue($daily->approximate);

        self::assertFalse(DealValues::of([$this->line('Licence', '10.00', unit: RecurrenceUnit::Month)])->approximate);
    }

    public function testTotalsPerCurrencyNeverMixed(): void
    {
        $value = DealValues::of([
            $this->line('Travel', '200.00', currency: Currency::of('EUR')),
            $this->line('Audit', '1000.00', currency: Currency::of('CZK')),
            $this->line('Report', '0.005', '1', currency: Currency::of('EUR')),
            $this->line('Workshop', '500.00', '2', currency: Currency::of('CZK')),
            $this->line('Support', '50.00', unit: RecurrenceUnit::Month, currency: Currency::of('EUR')),
            $this->line('Hosting', '100.00', unit: RecurrenceUnit::Month, currency: Currency::of('CZK')),
        ]);

        // One sum per currency, never a converted one, in the order of the codes.
        self::assertSame(['CZK' => 200_000, 'EUR' => 20_001], $this->minor($value->oneOff));
        self::assertSame(['CZK' => 10_000, 'EUR' => 5_000], $this->minor($value->monthly));
        self::assertSame(Currency::of('EUR'), $value->oneOff['EUR']->currency());
        self::assertSame(Currency::of('CZK'), $value->oneOff['CZK']->currency());
    }

    public function testEachLineIsRoundedHalfUpBeforeTheSum(): void
    {
        // 0.5 × 0.01 is half a minor unit: each line rounds up to 0.01, so two make 0.02, not 0.01 from summing first.
        $value = DealValues::of([
            $this->line('A', '0.01', '0.5'),
            $this->line('B', '0.01', '0.5'),
        ]);

        self::assertSame(['CZK' => 2], $this->minor($value->oneOff));
    }

    public function testNoPricedLinesNoValue(): void
    {
        $none = DealValues::of([]);
        self::assertFalse($none->hasValue());
        self::assertSame([], $none->oneOff);
        self::assertSame([], $none->monthly);
        self::assertFalse($none->approximate);

        $unpriced = DealValues::of([$this->line('Agreed later', null), $this->line('Weekly, agreed later', null, unit: RecurrenceUnit::Week)]);
        self::assertFalse($unpriced->hasValue());
        // A line without a price adds nothing, not even the approximation.
        self::assertFalse($unpriced->approximate);

        $mixed = DealValues::of([$this->line('Agreed later', null), $this->line('Audit', '10.00')]);
        self::assertTrue($mixed->hasValue());
        self::assertSame(['CZK' => 1_000], $this->minor($mixed->oneOff));

        $monthlyOnly = new DealValue([], ['CZK' => Money::ofMinor(1, Currency::of('CZK'))], false);
        self::assertTrue($monthlyOnly->hasValue());
    }

    public function testHourlyLinesAreNeverAddedToOneOffOrMonthly(): void
    {
        $value = DealValues::of([
            $this->line('Audit', '1000.00'),
            $this->line('Support', '500.00', unit: RecurrenceUnit::Month),
            $this->hourly('Consulting', '1200.00', null),
        ]);

        self::assertTrue($value->hasValue());
        self::assertSame(['CZK' => 100_000], $this->minor($value->oneOff));
        self::assertSame(['CZK' => 50_000], $this->minor($value->monthly));
        self::assertCount(1, $value->hourly['CZK']);
        self::assertSame(120_000, $value->hourly['CZK'][0]->rate->minor());
        self::assertTrue(DealValues::of([$this->hourly('Consulting', '1200.00', null)])->hasValue());
        self::assertSame([], DealValues::of([$this->hourly('Consulting', '1200.00', null)])->oneOff);
    }

    public function testExpectedHoursGiveAnExpectedAmount(): void
    {
        $value = DealValues::of([
            $this->hourly('Consulting', '1200.00', '40'),
            $this->hourly('Night work', '0.01', '0.5'),
            $this->hourly('Open ended', '900.00', null),
        ]);

        $lines = $value->hourly['CZK'];
        self::assertSame('40.000', $lines[0]->expectedHours);
        self::assertSame(4_800_000, $lines[0]->expectedAmount?->minor());
        // Half a minor unit rounds half-up, as the other lines do.
        self::assertSame(1, $lines[1]->expectedAmount?->minor());
        self::assertNull($lines[2]->expectedHours);
        self::assertNull($lines[2]->expectedAmount);
    }

    public function testTheSarahCase(): void
    {
        $value = DealValues::of([
            $this->line('Monthly flat fee', '20000.00', unit: RecurrenceUnit::Month),
            $this->hourly('Changes', '1200.00', null),
        ]);

        self::assertSame(['CZK' => 2_000_000], $this->minor($value->monthly));
        self::assertSame([], $value->oneOff);
        self::assertSame(120_000, $value->hourly['CZK'][0]->rate->minor());
        self::assertNull($value->hourly['CZK'][0]->expectedAmount);
    }

    private function hourly(string $name, string $rate, ?string $hours): DealLine
    {
        return new DealLine('0192f1a4-0000-7000-8000-000000000001', 0, null, new DealLineTerms(
            $name,
            'h',
            $hours,
            Money::of($rate, Currency::of('CZK')),
            BillingKind::TimeBased,
            null,
            null,
            null,
        ));
    }

    /**
     * @param array<string, Money> $totals
     *
     * @return array<string, int>
     */
    private function minor(array $totals): array
    {
        return array_map(static fn (Money $money): int => $money->minor(), $totals);
    }

    /**
     * A line with a price that may have more decimals than the currency, as an
     * exact price of a product never has: the value still rounds once per line.
     */
    private function line(string $name, ?string $price, string $quantity = '1', ?RecurrenceUnit $unit = null, int $interval = 1, ?Currency $currency = null): DealLine
    {
        $currency ??= Currency::of('CZK');
        $money = null === $price ? null : Money::of($price, $currency);

        return new DealLine('0192f1a4-0000-7000-8000-000000000001', 0, null, new DealLineTerms(
            $name,
            'piece',
            $quantity,
            $money,
            $unit instanceof RecurrenceUnit ? BillingKind::Recurring : BillingKind::OneOff,
            $unit,
            $unit instanceof RecurrenceUnit ? $interval : null,
            null,
        ));
    }
}
