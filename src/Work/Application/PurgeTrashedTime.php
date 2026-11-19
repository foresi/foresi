<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

/**
 * Removes for good the time that has been in the trash for more than 30 days,
 * as tasks are. The audit log keeps what it was.
 */
final readonly class PurgeTrashedTime
{
    public const int DAYS = PurgeDeletedTasks::DAYS;

    public function __construct(private TimeEntries $entries, private WorkUnit $unit)
    {
    }

    /**
     * @return int how many entries were removed
     */
    public function run(\DateTimeImmutable $now): int
    {
        $expired = $this->entries->trashedBefore($now->modify(sprintf('-%d days', self::DAYS)));
        if ([] === $expired) {
            return 0;
        }
        $this->unit->run(function () use ($expired): void {
            foreach ($expired as $entry) {
                $this->entries->remove($entry);
            }
        });

        return \count($expired);
    }
}
