<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Application;

use App\Shared\Domain\Money;
use App\Work\Application\TimeEntryRow;

/**
 * An entry that may go on the invoice, with the rate the firm bills it at;
 * null when no level has one.
 */
final readonly class TimeCandidate
{
    public function __construct(
        public TimeEntryRow $entry,
        public ?Money $rate,
    ) {
    }
}
