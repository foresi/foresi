<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\TaxesAndContributions\CzechRepublic;

use Foresi\Country\CzechRepublic\Calendar\CzechWorkingDays;
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
     * The day itself when it is a working day, else the next one
     * (CzechWorkingDays, shared with the other Czech deadlines).
     */
    public static function workingDayFrom(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return CzechWorkingDays::workingDayFrom($day);
    }

    public static function isDayOff(\DateTimeImmutable $day): bool
    {
        return CzechWorkingDays::isDayOff($day);
    }
}
