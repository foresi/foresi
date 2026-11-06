<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Schedule;

use App\Tenancy\Application\Schedule\Heartbeats;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/**
 * Records that a worker runs: when it starts, then at most once every EVERY
 * seconds of its loop, which comes round at least every second while it waits,
 * so the database is written once a minute, not once a second.
 */
#[AsEventListener(WorkerStartedEvent::class)]
#[AsEventListener(WorkerRunningEvent::class)]
final class WorkerHeartbeatListener
{
    public const int EVERY = 60;

    private ?int $lastBeat = null;

    public function __construct(
        private readonly Heartbeats $heartbeats,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(): void
    {
        $now = $this->clock->now()->getTimestamp();
        if (null !== $this->lastBeat && $now - $this->lastBeat < self::EVERY) {
            return;
        }
        $this->heartbeats->beat(Heartbeats::WORKER);
        $this->lastBeat = $now;
    }
}
