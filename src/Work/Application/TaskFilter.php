<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Work\Domain\StatusType;

/**
 * What the list and the board narrow their top-level tasks to; null is no narrowing.
 */
final readonly class TaskFilter
{
    public function __construct(
        public ?string $responsibleId = null,
        public ?StatusType $statusType = null,
        public ?DueBucket $due = null,
    ) {
    }
}
