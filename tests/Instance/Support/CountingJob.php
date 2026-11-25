<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Instance\Support;

use Foresi\Instance\Contract\ScheduledJob;

/**
 * A daily job that remembers each run: the last period it was given, and the zone
 * of each period it was asked for. Its day is the calendar day of the zone. It can be told to fail. The record is
 * static, as the job is a service of the container and the test reads it.
 */
final class CountingJob implements ScheduledJob
{
    public const string NAME = 'counting';

    /** @var list<array{lastPeriod: ?string}> */
    private static array $runs = [];

    /** @var list<string> */
    private static array $zones = [];

    private static int $failures = 0;

    public function name(): string
    {
        return self::NAME;
    }

    public function period(\DateTimeImmutable $now, \DateTimeZone $zone): string
    {
        self::$zones[] = $zone->getName();

        return $now->setTimezone($zone)->format('Y-m-d');
    }

    public function run(?string $lastPeriod): void
    {
        if (self::$failures > 0) {
            --self::$failures;

            throw new \RuntimeException('Planned failure.');
        }

        self::$runs[] = ['lastPeriod' => $lastPeriod];
    }

    public static function reset(): void
    {
        self::$runs = [];
        self::$zones = [];
        self::$failures = 0;
    }

    public static function failNext(int $times): void
    {
        self::$failures = $times;
    }

    /**
     * @phpstan-impure
     *
     * @return list<array{lastPeriod: ?string}>
     */
    public static function runs(): array
    {
        return self::$runs;
    }

    /**
     * @return list<string>
     */
    public static function zones(): array
    {
        return self::$zones;
    }
}
