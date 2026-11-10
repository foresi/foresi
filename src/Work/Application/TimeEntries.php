<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Identity\Domain\Membership;
use App\Work\Domain\Task;
use App\Work\Domain\TimeEntry;

/**
 * The time logged in the active tenant.
 */
interface TimeEntries
{
    public function entry(string $id): ?TimeEntry;

    /**
     * The timer the person runs, if any.
     */
    public function running(Membership $membership): ?TimeEntry;

    /**
     * @return list<TimeEntry>
     */
    public function ofTask(Task $task): array;

    public function add(TimeEntry $entry): void;

    /**
     * Removes for good; the audit log keeps what it was.
     */
    public function remove(TimeEntry $entry): void;
}
