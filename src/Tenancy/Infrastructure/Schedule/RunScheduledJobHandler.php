<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Schedule;

use App\Tenancy\Application\Schedule\Claim;
use App\Tenancy\Application\Schedule\RunScheduledJob;
use App\Tenancy\Application\Schedule\ScheduledJob;
use App\Tenancy\Application\Schedule\ScheduledJobRuns;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Runs a job in the tenant of the message, which the tenant middleware has made
 * active. The claim makes a second delivery of the same period a no-op. A failure
 * is recorded on the run and gives the period back, so the next tick retries it
 * instead of the queue: the message itself is done.
 */
#[AsMessageHandler]
final readonly class RunScheduledJobHandler
{
    private const int ERROR_LENGTH = 2000;

    /**
     * @param iterable<ScheduledJob> $jobs
     */
    public function __construct(
        private ScheduledJobRuns $runs,
        private LoggerInterface $logger,
        #[AutowireIterator('app.scheduled_job')]
        private iterable $jobs,
    ) {
    }

    public function __invoke(RunScheduledJob $message): void
    {
        $job = $this->job($message->job);
        $claim = $this->runs->claim($message->job, $message->period);
        if (!$claim instanceof Claim) {
            return;
        }

        try {
            $job->run($claim->lastPeriod);
        } catch (\Throwable $failure) {
            $this->runs->giveBack($claim, mb_substr(\sprintf('%s: %s', $failure::class, $failure->getMessage()), 0, self::ERROR_LENGTH));
            $this->logger->error('The scheduled job {job} failed for {period}; the next tick retries it.', ['job' => $message->job, 'period' => $message->period, 'exception' => $failure]);

            return;
        }

        $this->runs->finish($claim);
    }

    private function job(string $name): ScheduledJob
    {
        foreach ($this->jobs as $job) {
            if ($job->name() === $name) {
                return $job;
            }
        }

        throw new UnrecoverableMessageHandlingException(\sprintf('No scheduled job is named "%s".', $name));
    }
}
