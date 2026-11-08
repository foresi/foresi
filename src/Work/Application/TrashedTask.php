<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * A task in the trash of its project: one that went there itself, not a
 * subtask that went with its task.
 */
final readonly class TrashedTask
{
    /**
     * @param string|null $parentTitle the task a subtask sits under
     * @param bool        $restorable  whether the signed-in person may restore it
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $parentTitle,
        public ?string $deletedByName,
        public \DateTimeImmutable $deletedAt,
        public bool $restorable,
    ) {
    }
}
