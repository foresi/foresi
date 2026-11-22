<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Activities\Application;

use Foresi\Activities\Application\ActivitiesRefused;
use Foresi\Activities\Application\ActivityTimes;
use Foresi\Tests\Activities\Support\ActivitiesTestCase;

/**
 * The dates and times of the inputs: spaces around them are ignored, and what
 * is not a date or a time, or only begins or ends like one, is refused.
 */
final class ActivityTimesTest extends ActivitiesTestCase
{
    public function testADateIsTrimmedAndBlankIsNone(): void
    {
        $times = $this->times();

        self::assertNull($times->date(null, 'from'));
        self::assertNull($times->date('', 'from'));
        self::assertNull($times->date('   ', 'from'));
        self::assertSame('2026-12-01', $times->date(' 2026-12-01 ', 'from')?->format('Y-m-d'));
        $this->assertRefused('date_invalid', fn (): ?\DateTimeImmutable => $times->date('2026-02-30', 'from'));
        $this->assertRefused('date_invalid', fn (): ?\DateTimeImmutable => $times->date('1 Dec 2026', 'from'));
    }

    public function testATimeIsTrimmedAndBlankIsNone(): void
    {
        $times = $this->times();

        self::assertNull($times->dateTime(null, 'plannedAt'));
        self::assertNull($times->dateTime('', 'plannedAt'));
        self::assertNull($times->dateTime("  \t", 'plannedAt'));
        self::assertSame('2026-12-01T10:00:00+00:00', $times->dateTime(' 2026-12-01T10:00:00Z ', 'plannedAt')?->format(\DATE_ATOM));
        self::assertSame('2026-12-01T09:30:00+01:00', $times->dateTime('2026-12-01 09:30:00+01:00', 'plannedAt')?->format(\DATE_ATOM));
    }

    public function testATimeIsTheWholeValue(): void
    {
        $times = $this->times();

        foreach (['+1 day 2026-12-01T10:00:00Z', '2026-12-01T10:00 +1 day', 'tomorrow', '2026-12-01', '2026-13-45T10:00:00Z'] as $value) {
            $this->assertRefused('time_invalid', fn (): ?\DateTimeImmutable => $times->dateTime($value, 'plannedAt'), $value);
        }
    }

    private function times(): ActivityTimes
    {
        return self::getContainer()->get(ActivityTimes::class);
    }

    /**
     * @param callable(): mixed $read
     */
    private function assertRefused(string $reason, callable $read, string $value = ''): void
    {
        try {
            $read();
            self::fail($value.' is refused.');
        } catch (ActivitiesRefused $refused) {
            self::assertSame($reason, $refused->reason(), $value);
        }
    }
}
