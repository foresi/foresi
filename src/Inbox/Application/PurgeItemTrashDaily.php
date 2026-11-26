<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Instance\Application\Schedule\DailyPeriod;
use Foresi\Instance\Contract\ScheduledJob;
use Psr\Clock\ClockInterface;

/**
 * Empties the inbox trash of what is older than 30 days, once a day from 03:00
 * in the instance's zone, as the trash of tasks and of time.
 */
final readonly class PurgeItemTrashDaily implements ScheduledJob
{
    public const string NAME = 'inbox-trash-purge';
    public const int START_HOUR = 3;

    public function __construct(private PurgeTrashedItems $purge, private ClockInterface $clock)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function period(\DateTimeImmutable $now, \DateTimeZone $zone): string
    {
        return DailyPeriod::of($now, $zone, self::START_HOUR);
    }

    public function run(?string $lastPeriod): void
    {
        $this->purge->run(\DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
