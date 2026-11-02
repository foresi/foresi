<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Schedule;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The one schedule of the application, consumed by the normal worker as the
 * transport scheduler_foresi. It only ticks: the tick handler finds the jobs that
 * are due in each tenant. The state survives a restart, so a tick missed while
 * no worker ran is made up once, and the lock keeps two workers from both ticking.
 */
#[AsSchedule('foresi')]
final readonly class ForesiSchedule implements ScheduleProviderInterface
{
    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheInterface $state,
        private LockFactory $locks,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return new Schedule()
            ->add(RecurringMessage::every('5 minutes', new ScheduleTick()))
            ->stateful($this->state)
            ->lock($this->locks->createLock('schedule-foresi'))
            ->processOnlyLastMissedRun(true);
    }
}
