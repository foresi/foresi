<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Column;

/**
 * One project of the index as its row shows it, every cell already in words; the
 * client's address is null for internal work, which has no client. The id reads the
 * project's custom fields. Each count has the address of the tasks it counts, null
 * when it counts none or the list of all tasks does not show them.
 */
final readonly class ProjectIndexRow
{
    public function __construct(
        public string $name,
        public ?string $client,
        public ?string $clientHref,
        public int $openTasks,
        public ?string $due,
        public string $id = '',
        public int $overdueTasks = 0,
        public int $unassignedTasks = 0,
        public ?string $openHref = null,
        public ?string $overdueHref = null,
        public ?string $unassignedHref = null,
    ) {
    }
}
