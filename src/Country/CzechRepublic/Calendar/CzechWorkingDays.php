<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\CzechRepublic\Calendar;

/**
 * The Czech working days: a weekend day and a public holiday are days off
 * (Act 245/2000 Coll.), and a deadline that falls on one moves to the next
 * working day (DŘ § 33 (4)). The integrations of the Country module use it
 * for their deadlines, so each keeps none of its own.
 */
final class CzechWorkingDays
{
    public const string HOLIDAYS_SOURCE = 'https://www.zakonyprolidi.cz/cs/2000-245';

    /** Public holidays by month and day (Act 245/2000 Coll.), beside Good Friday and Easter Monday. */
    private const array HOLIDAYS = ['01-01', '05-01', '05-08', '07-05', '07-06', '09-28', '10-28', '11-17', '12-24', '12-25', '12-26'];

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
