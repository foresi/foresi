<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * One line of the totals of a time report: a project, a person or a task.
 */
final readonly class TimeTotal
{
    /**
     * @param ?string $label  null for the time of a project without a task, by task
     * @param ?string $detail the project of a task, by task
     */
    public function __construct(
        public string $key,
        public ?string $label,
        public ?string $detail,
        public int $minutes,
        public int $billableMinutes,
        public int $entries,
    ) {
    }
}
