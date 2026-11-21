<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\TaxesAndContributions\CzechRepublic;

use Foresi\TaxesAndContributions\Contract\FlatRateMonth;

/**
 * The Czech flat-rate tax (paušální daň, ZDP § 2a, § 7a, § 38lk): in a month
 * of band 1, 2 or 3 the band's monthly amount replaces social, health and
 * income tax, split into the three parts the statute names (SP act § 14 (3),
 * ZP act § 3a (5)). Its advance is due by the 20th of the month, the first
 * month by the 20th of the next (ZDP § 38lk); it has no settlement.
 */
final class CzechFlatRate
{
    /** @var list<string> */
    public const array BANDS = ['1', '2', '3'];

    /** The parts of a band's monthly amount, in the order of the payment. */
    public const array PARTS = ['income_tax', 'social', 'health'];

    /** The day of the month a monthly advance is due by. */
    public const int ADVANCE_DAY = 20;

    /** The 2027 band 1 is 9,661 by the statute and 9,662 in some sources; it is the only band not yet published. */
    public const string UNVERIFIED_2027_BAND_1 = 'flat_rate_2027_band_1';

    /**
     * The band's amount of a flat-rate month, and whether a value of it is
     * not yet published.
     *
     * @param \Closure(string, \DateTimeImmutable): RuleValue $rule
     *
     * @return array{FlatRateMonth, bool}
     */
    public static function month(Month $month, \Closure $rule): array
    {
        $band = $month->flatRateBand ?? throw new \LogicException('The month is not in the flat-rate tax.');
        $parts = [];
        $unverified = false;
        foreach (self::PARTS as $part) {
            $value = $rule(self::key($band, $part), $month->first);
            $parts[] = Czk::money($value->minor());
            $unverified = $unverified || $value->unverified;
        }

        return [new FlatRateMonth($month->key(), $band, ...$parts), $unverified];
    }

    public static function key(string $band, string $part): string
    {
        return 'flat_rate.band_'.$band.'.'.$part;
    }

    /**
     * The day a month's advance is due: the 20th of the month, or of the
     * next one for the first month of the regime, on the next working day.
     */
    public static function dueOn(\DateTimeImmutable $first, bool $firstMonth): \DateTimeImmutable
    {
        $month = $firstMonth ? $first->modify('first day of next month') : $first;

        return CzechDeadlines::workingDayFrom($month->setDate((int) $month->format('Y'), (int) $month->format('n'), self::ADVANCE_DAY));
    }
}
