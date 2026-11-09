<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * An entry of time as a line of a task, a week or the running timer.
 */
final readonly class TimeEntryRow
{
    /**
     * @param ?int $minutes  null while the timer runs
     * @param bool $editable whether the signed-in person may change or delete it
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $projectName,
        public ?string $taskId,
        public ?string $taskTitle,
        public string $membershipId,
        public string $personName,
        public \DateTimeImmutable $workedOn,
        public ?int $minutes,
        public ?\DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $endedAt,
        public string $note,
        public bool $billable,
        public bool $running,
        public bool $invoiced,
        public bool $editable,
    ) {
    }
}
