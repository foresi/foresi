<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\TaxesAndContributions\CzechRepublic;

use Foresi\TaxesAndContributions\Contract\ReturnRoute;

/**
 * The Czech deadlines of a tax year. The return is due three months after
 * the year on paper, four months when filed electronically, and six months
 * through a tax advisor (DŘ § 136); a deadline on a weekend or a public
 * holiday moves to the next working day (DŘ § 33 (4)), so the electronic one
 * for 2026 is 3 May 2027. The tax is paid by the same day (DŘ § 135 (3)). The
 * overviews for social and health insurance are due one month after the
 * return (SP act § 15 (1), ZP act § 24 (2)), and a shortfall 8 days after the
 * overview (SP act § 14a (3), ZP act § 8 (5)).
 */
final class CzechDeadlines
{
    public const string SOURCE = 'https://www.zakonyprolidi.cz/cs/2009-280';

    /** The days after the overview by which a shortfall is paid. */
    public const int SETTLEMENT_DAYS = 8;

    /** Public holidays by month and day (Act 245/2000 Coll.), beside Good Friday and Easter Monday. */
    private const array HOLIDAYS = ['01-01', '05-01', '05-08', '07-05', '07-06', '09-28', '10-28', '11-17', '12-24', '12-25', '12-26'];

    public static function returnDeadline(int $taxYear, ReturnRoute $route, \DateTimeZone $zone): \DateTimeImmutable
    {
        $month = match ($route) {
            ReturnRoute::Paper => 4,
            ReturnRoute::Electronic => 5,
            ReturnRoute::Advisor => 7,
        };

        return self::workingDayFrom(new \DateTimeImmutable(\sprintf('%04d-%02d-01', $taxYear + 1, $month), $zone));
    }

    /**
     * The day the overviews of the tax year are due: one month after the
     * return deadline.
     */
    public static function overviewDeadline(int $taxYear, ReturnRoute $route, \DateTimeZone $zone): \DateTimeImmutable
    {
        return self::returnDeadline($taxYear, $route, $zone)->modify('+1 month');
    }

    /**
     * The day itself when it is a working day, else the next one.
     */
    public static function workingDayFrom(\DateTimeImmutable $day): \DateTimeImmutable
    {
        while (self::isDayOff($day)) {
            $day = $day->modify('+1 day');
        }

        return $day;
    }

    public static function isDayOff(\DateTimeImmutable $day): bool
    {
        if ((int) $day->format('N') >= 6 || \in_array($day->format('m-d'), self::HOLIDAYS, true)) {
            return true;
        }
        $easter = self::easterSunday((int) $day->format('Y'), $day->getTimezone());

        return \in_array($day->format('Y-m-d'), [$easter->modify('-2 days')->format('Y-m-d'), $easter->modify('+1 day')->format('Y-m-d')], true);
    }

    /**
     * Easter Sunday of the Gregorian calendar (the anonymous algorithm, as
     * Meeus gives it).
     */
    private static function easterSunday(int $year, \DateTimeZone $zone): \DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(\sprintf('%04d-%02d-%02d', $year, $month, $day), $zone);
    }
}
