<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Instance\Contract;

/**
 * Timed work done once per period. A tick of the schedule asks each
 * job for the period of now; the job runs when that period has
 * not run yet. A failed run gives its period back, so the next tick tries again.
 *
 * A daily job computes its period in the instance's zone, with DailyPeriod, not
 * from UTC; see ADR 0017. Implementations are tagged TAG by autoconfiguration.
 */
interface ScheduledJob
{
    public const string TAG = 'foresi.instance.scheduled_job';

    /**
     * A stable name, at most 64 characters: runs are recorded under it.
     */
    public function name(): string;

    /**
     * The period now falls in. Periods of one job must sort byte by byte in time
     * order, so they are written at one fixed width, such as "2026-11-01", at
     * most 32 characters.
     *
     * @param \DateTimeZone $zone the zone of the instance; a job on UTC periods ignores it
     */
    public function period(\DateTimeImmutable $now, \DateTimeZone $zone): string;

    /**
     * Does the work of the current period.
     *
     * @param string|null $lastPeriod the last period that finished, so a job can catch up; null on the first run
     */
    public function run(?string $lastPeriod): void;
}
