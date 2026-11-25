<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Instance\Application\Schedule;

use Foresi\Instance\Contract\ScheduledJob;

/**
 * Timed work of the installation itself, such as
 * polling the operator's inbox. The schedule sends it at its own pace; a run
 * holds a lock named after the task, so a second worker at the same moment
 * skips it. A failed run is logged, and the next one simply comes later: a task
 * must leave nothing half done that the next run cannot pick up.
 *
 * Unlike a ScheduledJob, there are no periods and no record of runs.
 * Implementations are tagged foresi.instance.operator_task.
 */
interface OperatorTask
{
    public const string TAG = 'foresi.instance.operator_task';

    /**
     * A stable name: the lock and the log name it.
     */
    public function name(): string;

    /**
     * How often to run, as the scheduler reads it, such as "5 minutes".
     */
    public function every(): string;

    /**
     * Does the work. A run may take at most ten minutes,
     * after which its lock may be taken by another worker.
     */
    public function run(): void;
}
