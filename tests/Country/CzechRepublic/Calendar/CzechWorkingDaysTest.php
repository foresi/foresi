<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Country\CzechRepublic\Calendar;

use Foresi\Country\CzechRepublic\Calendar\CzechWorkingDays;
use PHPUnit\Framework\TestCase;

/**
 * The Czech days off: weekends, the fixed public holidays, and Good Friday
 * and Easter Monday, which move with Easter.
 */
final class CzechWorkingDaysTest extends TestCase
{
    /**
     * Easter Sunday of the years from 2024 to 2040, as published.
     */
    private const array EASTER = [
        2024 => '03-31', 2025 => '04-20', 2026 => '04-05', 2027 => '03-28', 2028 => '04-16', 2029 => '04-01',
        2030 => '04-21', 2031 => '04-13', 2032 => '03-28', 2033 => '04-17', 2034 => '04-09', 2035 => '03-25',
        2036 => '04-13', 2037 => '04-05', 2038 => '04-25', 2039 => '04-10', 2040 => '04-01', 2049 => '04-18',
        2100 => '03-28', 2285 => '03-22',
    ];

    public function testGoodFridayAndEasterMondayAreDaysOffEveryYear(): void
    {
        $zone = new \DateTimeZone('Europe/Prague');
        foreach (self::EASTER as $year => $sunday) {
            $easter = new \DateTimeImmutable($year.'-'.$sunday, $zone);
            self::assertTrue(CzechWorkingDays::isDayOff($easter->modify('-2 days')), 'Good Friday '.$year);
            self::assertTrue(CzechWorkingDays::isDayOff($easter->modify('+1 day')), 'Easter Monday '.$year);
            self::assertFalse(CzechWorkingDays::isDayOff($easter->modify('-3 days')), 'Thursday before Easter '.$year);
            self::assertFalse(CzechWorkingDays::isDayOff($easter->modify('+2 days')), 'Tuesday after Easter '.$year);
            self::assertFalse(CzechWorkingDays::isDayOff($easter->modify('-9 days')), 'Friday a week before Easter '.$year);
            self::assertFalse(CzechWorkingDays::isDayOff($easter->modify('+8 days')), 'Monday a week after Easter '.$year);
        }
    }

    public function testAWeekendDayIsOffAndAWorkingDayIsKept(): void
    {
        $zone = new \DateTimeZone('Europe/Prague');
        self::assertTrue(CzechWorkingDays::isDayOff(new \DateTimeImmutable('2026-11-21', $zone)), 'Saturday');
        self::assertTrue(CzechWorkingDays::isDayOff(new \DateTimeImmutable('2026-11-22', $zone)), 'Sunday');
        self::assertFalse(CzechWorkingDays::isDayOff(new \DateTimeImmutable('2026-11-20', $zone)), 'Friday');
        self::assertTrue(CzechWorkingDays::isDayOff(new \DateTimeImmutable('2026-11-17', $zone)), 'Struggle for Freedom and Democracy Day');
        self::assertSame('2026-12-28', CzechWorkingDays::workingDayFrom(new \DateTimeImmutable('2026-12-24', $zone))->format('Y-m-d'));
        self::assertSame('2026-12-23', CzechWorkingDays::workingDayFrom(new \DateTimeImmutable('2026-12-23', $zone))->format('Y-m-d'));
    }
}
