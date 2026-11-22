<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Shared\Domain\Money;
use Foresi\Vat\Contract\ScheduledVat;

/**
 * The output VAT of one occurrence of a schedule in CZK. An amount in
 * another currency is converted at today's rate, as the cash-flow forecast
 * converts it, and says so with $fxToday; one whose currency has no rate
 * today counts zero and says so with $missingCzk.
 */
final readonly class ScheduleAmount
{
    public function __construct(
        public ScheduledVat $schedule,
        public Money $output,
        public bool $fxToday = false,
        public bool $missingCzk = false,
    ) {
    }
}
