<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

/**
 * Removes for good the labels that have been in the trash for more than 30
 * days, as tasks are, and with each the tasks it was on keep it no more. The
 * audit log keeps what it was.
 */
final readonly class PurgeTrashedLabels
{
    public const int DAYS = PurgeDeletedTasks::DAYS;

    public function __construct(private Labels $labels, private Tasks $tasks, private WorkUnit $unit)
    {
    }

    /**
     * @return int how many labels were removed
     */
    public function run(\DateTimeImmutable $now): int
    {
        $expired = $this->labels->trashedBefore($now->modify(sprintf('-%d days', self::DAYS)));
        if ([] === $expired) {
            return 0;
        }
        $this->unit->run(function () use ($expired): void {
            foreach ($expired as $label) {
                foreach ($this->tasks->labelled($label) as $taskLabel) {
                    $this->tasks->remove($taskLabel);
                }
                $this->labels->remove($label);
            }
        });

        return \count($expired);
    }
}
