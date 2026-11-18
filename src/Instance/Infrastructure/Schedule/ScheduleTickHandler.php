<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Instance\Infrastructure\Schedule;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Instance\Application\Schedule\Heartbeats;
use Foresi\Instance\Application\Schedule\RunScheduledJob;
use Foresi\Instance\Application\Schedule\ScheduledJob;
use Foresi\Instance\Application\Schedule\ScheduledJobRuns;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Fans a tick out: one RunScheduledJob per job whose period has not run yet.
 * Each job is asked for the period in the instance's zone. A period that has run, or is queued already, sends nothing, so a tick stays cheap
 * and a busy worker is not handed the same period twice. Each tick is recorded,
 * so the health check knows the schedule runs.
 */
#[AsMessageHandler]
final readonly class ScheduleTickHandler
{
    /**
     * @param iterable<ScheduledJob> $jobs
     */
    public function __construct(
        private InstanceClock $instance,
        private ScheduledJobRuns $runs,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private Heartbeats $heartbeats,
        #[AutowireIterator('app.scheduled_job')]
        private iterable $jobs,
    ) {
    }

    public function __invoke(ScheduleTick $tick): void
    {
        $this->heartbeats->beat(Heartbeats::SCHEDULE);
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $zone = $this->instance->timezone();
        foreach ($this->jobs as $job) {
            $period = $job->period($now, $zone);
            if ($this->runs->queue($job->name(), $period)) {
                $this->bus->dispatch(new RunScheduledJob($job->name(), $period));
            }
        }
    }
}
