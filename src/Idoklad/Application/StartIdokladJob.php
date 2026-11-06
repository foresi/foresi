<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application;

use App\Idoklad\Application\Message\ProcessIdokladJob;
use App\Idoklad\Domain\IdokladJob;
use App\Idoklad\Domain\IdokladJobKind;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\LegalEntity;
use Psr\Clock\ClockInterface;

/**
 * Asks a worker for a pull, check or run of a firm. One job of a firm at a time:
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

    public function start(LegalEntity $firm, IdokladJobKind $kind, ?\DateTimeImmutable $remindFrom = null): IdokladJob
    {
        $busy = $this->jobs->busy($firm);
        if ($busy instanceof IdokladJob) {
            return $busy;
        }
        $job = $this->work->run(function () use ($firm, $kind, $remindFrom): IdokladJob {
            $job = new IdokladJob($firm, $kind, \DateTimeImmutable::createFromInterface($this->clock->now()), $remindFrom);
            $this->jobs->add($job);

            return $job;
        });
        $this->queue->dispatch(new ProcessIdokladJob($job->id()));

        return $job;
    }
}
