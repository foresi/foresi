<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Schedule;

/**
 * When the worker and the schedule last showed they run. The worker beats at
 * least once a minute while it runs, the schedule on every tick, every five
 * minutes; the web reads both, as it shares the database with the worker.
 */
interface Heartbeats
{
    public const string WORKER = 'worker';
    public const string SCHEDULE = 'schedule';

    /** Seconds after the last beat of the worker that it counts as stopped. */
    public const int WORKER_STALE_AFTER = 180;

    /** Seconds after the last tick that the schedule counts as stopped: three ticks missed. */
    public const int SCHEDULE_STALE_AFTER = 900;

    public function beat(string $name): void;

    public function last(string $name): ?\DateTimeImmutable;
}
