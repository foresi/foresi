<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Column;

/**
 * One row of the inbox as its cells show it, every cell already in words.
 */
final readonly class NotificationCells
{
    public function __construct(
        public string $task,
        public string $project,
        public string $event,
        public string $at,
    ) {
    }
}
