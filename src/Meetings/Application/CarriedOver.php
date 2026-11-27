<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application;

use Foresi\Meetings\Domain\Meeting;
use Foresi\Work\Contract\TaskSummary;

/**
 * The open tasks agreed at one meeting of a series, as a later meeting shows them.
 */
final readonly class CarriedOver
{
    /**
     * @param non-empty-list<TaskSummary> $tasks in the order made
     */
    public function __construct(
        public Meeting $meeting,
        public array $tasks,
    ) {
    }
}
