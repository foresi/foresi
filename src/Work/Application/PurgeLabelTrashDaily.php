<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Instance\Application\Schedule\DailyPeriod;
use Foresi\Instance\Application\Schedule\ScheduledJob;
use Psr\Clock\ClockInterface;

/**
 * Empties the trash of labels of what is older than 30 days, once a day from
 * 03:00 in the instance's zone, as the trash of tasks.
 */
final readonly class PurgeLabelTrashDaily implements ScheduledJob
{
    public const string NAME = 'label-trash-purge';
    public const int START_HOUR = PurgeTaskTrashDaily::START_HOUR;

    public function __construct(private PurgeTrashedLabels $purge, private ClockInterface $clock)
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
