<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Integration\Invoicing;

use Foresi\Shared\Domain\Money;
use Foresi\Timesheet\Application\TimeEntryRow;

/**
 * An entry that may go on the invoice, with the rate the firm bills it at and
 * the level that set it; null when no level has one.
 */
final readonly class TimeCandidate
{
    public function __construct(
        public TimeEntryRow $entry,
        public ?Money $rate,
        public ?RateSource $source = null,
    ) {
    }
}
