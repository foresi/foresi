<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

/**
 * Time in the trash of its project.
 */
final readonly class TrashedTime
{
    /**
     * @param string|null $taskTitle  the task it was logged on, null for the project
     * @param bool        $restorable whether the signed-in person may restore it
     */
    public function __construct(
        public string $id,
        public \DateTimeImmutable $workedOn,
        public string $personName,
        public int $minutes,
        public ?string $taskTitle,
        public ?string $deletedByName,
        public \DateTimeImmutable $deletedAt,
        public bool $restorable,
    ) {
    }
}
