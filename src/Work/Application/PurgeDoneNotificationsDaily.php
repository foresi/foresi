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
 * Forgets the notifications marked done more than three months ago, once a
 * day from 03:00 in the tenant's zone (GitHub keeps done ones for a while,
 * then lets them go). A missed day needs no catching up.
 */
final readonly class PurgeDoneNotificationsDaily implements ScheduledJob
{
    public const string NAME = 'notification-purge';
    public const int START_HOUR = 3;
    public const int MONTHS = 3;

    public function __construct(private Notifications $notifications, private WorkUnit $unit, private ClockInterface $clock)
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
        $this->purge(\DateTimeImmutable::createFromInterface($this->clock->now()));
    }

    /**
     * @return int how many rows were removed
     */
    public function purge(\DateTimeImmutable $now): int
    {
        $old = $this->notifications->doneBefore($now->modify(sprintf('-%d months', self::MONTHS)));
        $this->unit->run(function () use ($old): void {
            foreach ($old as $notification) {
                $this->notifications->remove($notification);
            }
        });

        return \count($old);
    }
}
