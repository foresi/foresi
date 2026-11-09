<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Work\Domain\NotificationKind;

/**
 * One row of the inbox: the task, the latest event and who did it, in the
 * zone of the organisation.
 */
final readonly class NotificationRow
{
    public function __construct(
        public string $id,
        public string $taskId,
        public string $taskTitle,
        public string $projectName,
        public NotificationKind $kind,
        public string $actorName,
        public \DateTimeImmutable $at,
        public bool $read,
        public int $events,
        public bool $done,
    ) {
    }
}
