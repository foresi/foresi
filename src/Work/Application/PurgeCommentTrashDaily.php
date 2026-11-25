<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Instance\Application\Schedule\DailyPeriod;
use Foresi\Instance\Contract\ScheduledJob;
use Psr\Clock\ClockInterface;

/**
 * Clears the comments deleted more than 30 days ago, once a day from 03:00 in
 * the instance's zone, as the trash of tasks.
 */
final readonly class PurgeCommentTrashDaily implements ScheduledJob
{
    public const string NAME = 'comment-trash-purge';
    public const int START_HOUR = PurgeTaskTrashDaily::START_HOUR;

    public function __construct(private PurgeDeletedComments $purge, private ClockInterface $clock)
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
