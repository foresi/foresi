<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Webhooks\Application;

use App\Webhooks\Application\RetrySchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

#[CoversClass(RetrySchedule::class)]
final class RetryScheduleTest extends TestCase
{
    public function testTheTenDelaysOfStandardWebhooks(): void
    {
        $delays = [];
        for ($made = 0; $made < 10; ++$made) {
            $delays[] = RetrySchedule::delayAfter($made);
        }

        self::assertSame([0, 5, 300, 1800, 7200, 18000, 36000, 50400, 72000, 86400], $delays);
    }

    public function testNoAttemptAfterTheTenth(): void
    {
        self::assertNull(RetrySchedule::delayAfter(10));
        self::assertNull(RetrySchedule::delayAfter(11));
    }

    public function testShortDelaysHaveNoJitter(): void
    {
        $random = new Randomizer(new Mt19937(1));
        for ($i = 0; $i < 50; ++$i) {
            self::assertSame(0, RetrySchedule::withJitter(0, $random));
            self::assertSame(5, RetrySchedule::withJitter(5, $random));
            self::assertSame(299, RetrySchedule::withJitter(299, $random));
        }
    }

    public function testJitterStaysWithinTenPercentFromFiveMinutes(): void
    {
        $random = new Randomizer(new Mt19937(7));
        foreach ([300, 1800, 86400] as $delay) {
            $seen = [];
            for ($i = 0; $i < 200; ++$i) {
                $jittered = RetrySchedule::withJitter($delay, $random);
                self::assertGreaterThanOrEqual((int) floor($delay * 0.9), $jittered);
                self::assertLessThanOrEqual((int) ceil($delay * 1.1), $jittered);
                $seen[$jittered] = true;
            }
            self::assertGreaterThan(1, \count($seen), 'The delay is spread, not fixed.');
        }
    }
}
