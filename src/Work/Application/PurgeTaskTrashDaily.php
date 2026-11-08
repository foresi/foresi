<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Tenancy\Application\Schedule\DailyPeriod;
use App\Tenancy\Application\Schedule\ScheduledJob;
use Psr\Clock\ClockInterface;

/**
 * Empties the trash of tasks of what is older than 30 days, once a day from
 * 03:00 in the tenant's zone, when nobody works. A missed day needs no catching
 * up: the next run takes everything that is old enough by then.
 */
final readonly class PurgeTaskTrashDaily implements ScheduledJob
{
    public const string NAME = 'task-trash-purge';
    public const int START_HOUR = 3;

    public function __construct(private PurgeDeletedTasks $purge, private ClockInterface $clock)
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
