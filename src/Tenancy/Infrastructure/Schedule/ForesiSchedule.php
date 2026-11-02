<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Schedule;

use App\Tenancy\Application\Schedule\OperatorTask;
use App\Tenancy\Application\Schedule\RunOperatorTask;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The one schedule of the application, consumed by the normal worker as the
 * transport scheduler_foresi. It ticks, and the tick handler finds the jobs that
 * are due in each tenant; and it sends each operator task at the task's own
 * pace. The state survives a restart, so a tick missed while no worker ran is
 * made up once, and the lock keeps two workers from both ticking.
 */
#[AsSchedule('foresi')]
final readonly class ForesiSchedule implements ScheduleProviderInterface
{
    /**
     * @param iterable<OperatorTask> $operatorTasks
     */
    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheInterface $state,
        private LockFactory $locks,
        #[AutowireIterator('app.operator_task')]
        private iterable $operatorTasks,
    ) {
    }

    public function getSchedule(): Schedule
    {
        $schedule = new Schedule()->add(RecurringMessage::every('5 minutes', new ScheduleTick()));
        foreach ($this->operatorTasks as $task) {
            $schedule->add(RecurringMessage::every($task->every(), new RunOperatorTask($task->name())));
        }

        return $schedule
            ->stateful($this->state)
            ->lock($this->locks->createLock('schedule-foresi'))
            ->processOnlyLastMissedRun(true);
    }
}
