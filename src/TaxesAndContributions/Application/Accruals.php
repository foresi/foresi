<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Application;

use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Contract\AccrualMonth;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\AmountsView;
use Foresi\TaxesAndContributions\Contract\MethodResult;
use Foresi\TaxesAndContributions\Contract\RegimeResult;

/**
 * The accrual view of the year end (old T6): the levies of the year spread
 * over its months by each month's share of the profit. A month whose share
 * would fall below its smallest advances, or that brings no profit, carries
 * just those, and the rest is spread over the others. When the levies do not
 * cover the smallest advances, they follow those advances instead. The months
 * always add up to the levies of the year, to the heller.
 */
final readonly class Accruals
{
    /**
     * Each expense method of the result, by its value.
     *
     * @return array<string, list<AccrualMonth>>
     */
    public static function of(RegimeResult $result, AmountsView $amounts): array
    {
        $profits = [];
        foreach ($amounts->sums as $sum) {
            $profits[$sum->month] = ($profits[$sum->month] ?? 0) + (AmountKind::Income === $sum->kind ? $sum->counted->minor() : -$sum->counted->minor());
        }
        $months = [];
        foreach ($result->months as $month) {
            $months[$month->month] = [$profits[$month->month] ?? 0, $month->minimumSocialAdvance->minor() + $month->minimumHealthAdvance->minor()];
        }
        $accruals = [];
        foreach ($result->methods as $method) {
            $accruals[$method->method->value] = self::spread($method, $months);
        }

        return $accruals;
    }

    /**
     * @param array<string, array{int, int}> $months the profit and the smallest advances of each month
     *
     * @return list<AccrualMonth>
     */
    private static function spread(MethodResult $method, array $months): array
    {
        $shares = self::shares($method->totals->levies, $months);
        $spread = [];
        foreach ($months as $key => [$profit, $minimum]) {
            $spread[] = new AccrualMonth($key, self::czk($profit), self::czk($minimum), self::czk($shares[$key]));
        }

        return $spread;
    }

    /**
     * @param array<string, array{int, int}> $months
     *
     * @return array<string, int>
     */
    private static function shares(Money $levies, array $months): array
    {
        $minimums = array_map(static fn (array $month): int => $month[1], $months);
        // Months without profit carry their minimum; then any month whose share would be lower joins them.
        $fixed = array_filter($months, static fn (array $month): bool => $month[0] <= 0);
        while (true) {
            $rest = $levies->minor() - array_sum(array_intersect_key($minimums, $fixed));
            $free = array_diff_key($months, $fixed);
            if ($rest < 0 || [] === $free) {
                return self::allocated($levies, array_sum($minimums) > 0 ? $minimums : array_map(static fn (): int => 1, $months));
            }
            $shares = self::allocated(Money::ofMinor($rest, Currency::CZK), array_map(static fn (array $month): int => $month[0], $free));
            $low = array_filter($shares, static fn (int $share, string $key): bool => $share < $minimums[$key], \ARRAY_FILTER_USE_BOTH);
            if ([] === $low) {
                return [...$minimums, ...$shares];
            }
            $fixed += array_intersect_key($months, $low);
        }
    }

    /**
     * @param array<string, int> $ratios
     *
     * @return array<string, int>
     */
    private static function allocated(Money $amount, array $ratios): array
    {
        $parts = $amount->allocate(...array_values($ratios));

        return array_combine(array_keys($ratios), array_map(static fn (Money $part): int => $part->minor(), $parts));
    }

    private static function czk(int $minor): Money
    {
        return Money::ofMinor($minor, Currency::CZK);
    }
}
