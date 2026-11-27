<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Idoklad\Application;

use Foresi\Idoklad\Application\Message\ProcessIdokladJob;
use Foresi\Idoklad\Domain\IdokladJob;
use Foresi\Idoklad\Domain\IdokladJobKind;
use Foresi\Idoklad\Domain\ImportOptions;
use Foresi\Shared\Application\TransactionalWork;
use Psr\Clock\ClockInterface;

/**
 * Asks a worker for a pull, check or run. One job at a time:
 * while one waits or runs, asking again gives that one.
 */
final readonly class StartIdokladJob
{
    public function __construct(
        private IdokladJobs $jobs,
        private IdokladQueue $queue,
        private TransactionalWork $work,
        private ClockInterface $clock,
    ) {
    }

    public function start(IdokladJobKind $kind, ?\DateTimeImmutable $remindFrom = null, ?ImportOptions $options = null): IdokladJob
    {
        $busy = $this->jobs->busy();
        if ($busy instanceof IdokladJob) {
            return $busy;
        }
        $job = $this->work->run(function () use ($kind, $remindFrom, $options): IdokladJob {
            $job = new IdokladJob($kind, \DateTimeImmutable::createFromInterface($this->clock->now()), $remindFrom, $options);
            $this->jobs->add($job);

            return $job;
        });
        $this->queue->dispatch(new ProcessIdokladJob($job->id()));

        return $job;
    }
}
