<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\RecurringState;

/**
 * Which recurring templates a list shows: of one firm or all, in one state or any,
 * only those whose last run failed, and which page.
 */
final readonly class RecurringFilter
{
    public function __construct(
        public ?string $firmId,
        public ?RecurringState $state,
        public bool $failing,
        public int $page,
    ) {
    }
}
