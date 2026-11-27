<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Instance\Application\Schedule\DailyPeriod;
use Foresi\Instance\Contract\ScheduledJob;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Work\Domain\RecurringTask;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates the tasks of recurring tasks once a day from 01:00 in the instance's
 * zone, as the recurring invoices are issued: each active recurring task whose
 * next task is to be created by today gets it, then the next, oldest first, each
 * date once whatever else runs (CreateRecurringOccurrence), at most MAX_PER_RUN
 * per recurring task. A worker that was down catches up; the rest follows the
 * next day. A new task comes even while the last one is open: each is its own slot.
 *
 * A date that cannot be created, in an archived project for example, is not
 * skipped in silence: its reason is recorded on the recurring task, which shows
 * it, and the next run tries again; a run that creates its task clears it.
 * Anything else is thrown, so the scheduler gives the day back and the next tick
 * retries it; what was created stays created and is not created twice.
 */
final readonly class CreateRecurringTasks implements ScheduledJob
{
    public const string NAME = 'recurring-tasks';
    public const int START_HOUR = 1;
    public const int MAX_PER_RUN = 4;

    public function __construct(
        private RecurringTasks $recurring,
        private CreateRecurringOccurrence $occurrences,
        private TransactionalWork $work,
        private InstanceClock $instanceClock,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function period(\DateTimeImmutable $now, \DateTimeZone $zone): string
    {
        return DailyPeriod::of($now, $zone, self::START_HOUR);
    }

    /**
     * Each recurring task's next date names what was missed, so the last period is not needed.
     */
    public function run(?string $lastPeriod): void
    {
        $today = $this->today();
        foreach ($this->recurring->due($today->modify(\sprintf('+%d days', RecurringTask::MAX_CREATE_DAYS_BEFORE))) as $id) {
            $this->runOne($id, $today);
        }
    }

    /**
     * Creates what one recurring task has due, as the run does, for one saved,
     * resumed or skipped after the day's run.
     *
     * @return list<string> the ids of the tasks created
     */
    public function runOne(string $id, ?\DateTimeImmutable $today = null): array
    {
        $today ??= $this->today();
        $created = [];
        $failure = null;
        for ($round = 0; $round < self::MAX_PER_RUN; ++$round) {
            try {
                $taskId = $this->occurrences->create($id, $today);
            } catch (OccurrenceRefused $refused) {
                $failure = $refused->reason;
                break;
            }
            if (null === $taskId) {
                break;
            }
            $created[] = $taskId;
        }
        $this->settle($id, $failure);

        return $created;
    }

    /**
     * The instance's day the run works for: before START_HOUR it is still the day before.
     */
    public function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->period(\DateTimeImmutable::createFromInterface($this->clock->now()), $this->instanceClock->timezone()));
    }

    private function settle(string $id, ?string $failure): void
    {
        $recurring = $this->recurring->find($id);
        if (!$recurring instanceof RecurringTask || $failure === $recurring->lastFailureCode()) {
            return;
        }
        if (null !== $failure) {
            $this->logger->warning('The recurring task {recurring} created no task: {code}.', ['recurring' => $id, 'code' => $failure]);
        }
        $at = \DateTimeImmutable::createFromInterface($this->clock->now());
        $this->work->run(function () use ($id, $failure, $at): void {
            $locked = $this->recurring->lock($id);
            if (null === $failure) {
                $locked?->clearFailure();
            } else {
                $locked?->recordFailure($at, $failure);
            }
        });
    }
}
