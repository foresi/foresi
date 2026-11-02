<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Schedule;

use App\Tenancy\Application\Schedule\RunScheduledJob;
use App\Tenancy\Application\Schedule\ScheduledJob;
use App\Tenancy\Application\Schedule\ScheduledJobRuns;
use App\Tenancy\Application\Schedule\TenantDirectory;
use App\Tenancy\Infrastructure\Messenger\TenantStamp;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Fans a tick out: one RunScheduledJob per tenant and job whose period has not
 * run yet, stamped with that tenant. No tenant is ever activated here, and a
 * period that has run, or is queued already, sends nothing, so a tick stays cheap
 * and a busy worker is not handed the same period twice.
 */
#[AsMessageHandler]
final readonly class ScheduleTickHandler
{
    /**
     * @param iterable<ScheduledJob> $jobs
     */
    public function __construct(
        private TenantDirectory $tenants,
        private ScheduledJobRuns $runs,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        #[AutowireIterator('app.scheduled_job')]
        private iterable $jobs,
    ) {
    }

    public function __invoke(ScheduleTick $tick): void
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        foreach ($this->tenants->ids() as $tenant) {
            foreach ($this->jobs as $job) {
                $period = $job->period($now);
                if ($this->runs->queue($tenant, $job->name(), $period)) {
                    $this->bus->dispatch(new Envelope(new RunScheduledJob($job->name(), $period), [new TenantStamp($tenant->toString())]));
                }
            }
        }
    }
}
