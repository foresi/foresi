<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application;

/**
 * When the attempts of a delivery are made, as Standard Webhooks suggests: at
 * once, then after 5 s, 5 min, 30 min, 2 h, 5 h, 10 h, 14 h, 20 h and 24 h, ten
 * attempts over about 75.5 hours. Delays of five minutes or more are spread by
 * up to a tenth either way, so the retries of an outage do not all come back at
 * the same second.
 */
final class RetrySchedule
{
    /** The delay before each attempt, in seconds; the first is at once. */
    public const array DELAYS = [0, 5, 300, 1800, 7200, 18000, 36000, 50400, 72000, 86400];

    /** The shortest delay that is spread. */
    public const int JITTER_FROM = 300;

    /**
     * The seconds to wait before the next attempt, or null when none is left.
     */
    public static function delayAfter(int $attemptsMade): ?int
    {
        return self::DELAYS[max(0, $attemptsMade)] ?? null;
    }

    public static function withJitter(int $seconds, \Random\Randomizer $random): int
    {
        if ($seconds < self::JITTER_FROM) {
            return $seconds;
        }
        $spread = intdiv($seconds, 10);

        return $seconds + $random->getInt(-$spread, $spread);
    }
}
