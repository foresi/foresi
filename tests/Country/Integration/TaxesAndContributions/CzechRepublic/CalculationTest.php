<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Country\Integration\TaxesAndContributions\CzechRepublic;

use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Calculation;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\CzechRuleBook;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\Month;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\AmountsView;
use Foresi\TaxesAndContributions\Contract\AmountTotals;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\ProfileView;
use Foresi\TaxesAndContributions\Contract\RegimeInput;
use Foresi\TaxesAndContributions\Contract\YearInputsView;
use PHPUnit\Framework\TestCase;

/**
 * One expense method on its way through the pipeline: the rule values it
 * reads by day and remembers, its months by activity, and the starting years.
 */
final class CalculationTest extends TestCase
{
    public function testARuleIsReadOnItsDayAndRememberedOncePerValue(): void
    {
        $calculation = $this->calculation(new YearInputsView(2026));

        self::assertSame('35', $calculation->rule('social.minimum_share.main', new \DateTimeImmutable('2026-07-01'))->value);
        self::assertSame('40', $calculation->rule('social.minimum_share.main')->value, 'Without a day, the first day of the year.');
        self::assertSame('40', $calculation->rule('social.minimum_share.main', new \DateTimeImmutable('2026-06-30'))->value);

        self::assertSame(['social.minimum_share.main@2026-07-01', 'social.minimum_share.main@2026-01-01'], array_keys($calculation->used()));
        self::assertSame(['35', '40'], array_map(static fn (\Foresi\TaxesAndContributions\Contract\RuleRef $ref): string => $ref->value, $calculation->usedRefs()));
        self::assertEquals(new \DateTimeImmutable('2026-01-01'), $calculation->yearStart());
    }

    public function testTheMonthsOfAnActivityAreAList(): void
    {
        $january = new Month(new \DateTimeImmutable('2026-01-01'), Month::MAIN, false, false, null);
        $february = new Month(new \DateTimeImmutable('2026-02-01'), Month::SECONDARY, false, false, null);
        $calculation = $this->calculation(new YearInputsView(2026), [$january, $february]);

        self::assertSame([$february], $calculation->monthsOf(Month::SECONDARY));
        self::assertSame([$january], $calculation->monthsOf(Month::MAIN));
        self::assertSame([], $calculation->monthsOf(Month::NONE));
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function starts(): iterable
    {
        yield 'no start' => [null, false];
        yield 'three years before' => ['2023-12-31', false];
        yield 'two years before' => ['2024-01-01', true];
        yield 'the year before' => ['2025-06-01', true];
        yield 'this year' => ['2026-12-31', true];
        yield 'next year' => ['2027-01-01', false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('starts')]
    public function testTheStartingMinimumHoldsForTheYearOfTheStartAndTheTwoAfter(?string $start, bool $starting): void
    {
        $inputs = new YearInputsView(2026, activityStart: null === $start ? null : new \DateTimeImmutable($start));

        self::assertSame($starting, $this->calculation($inputs)->isStarting());
    }

    public function testAFlagOrAWarningIsListedOnce(): void
    {
        $calculation = $this->calculation(new YearInputsView(2026));

        $calculation->flag(Calculation::UNVERIFIED_MIXED_YEAR);
        $calculation->flag(Calculation::UNVERIFIED_MIXED_YEAR);
        $calculation->warn(Calculation::WARNING_DEDUCTION_NOT_COMPUTED);
        $calculation->warn(Calculation::WARNING_DEDUCTION_NOT_COMPUTED);

        self::assertSame([Calculation::UNVERIFIED_MIXED_YEAR], $calculation->unverified);
        self::assertSame([Calculation::WARNING_DEDUCTION_NOT_COMPUTED], $calculation->warnings);
        self::assertTrue($calculation->has('credit.taxpayer'));
        self::assertFalse($calculation->has('credit.student'));
    }

    /**
     * @param list<Month> $months
     */
    private function calculation(YearInputsView $inputs, array $months = []): Calculation
    {
        $zero = Money::ofMinor(0, Currency::of('CZK'));
        $totals = new AmountTotals($zero, $zero, $zero, $zero);
        $amounts = new AmountsView(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'), [], [AmountKind::Income->value => $totals, AmountKind::Cost->value => $totals], [], [], [], [], [], []);
        $end = new \DateTimeImmutable('2026-12-31');

        return new Calculation(new RegimeInput(2026, new ProfileView([], [2026 => $inputs], []), $amounts, $end, $end), $inputs, ExpenseMethod::Actual, $months, new CzechRuleBook());
    }
}
