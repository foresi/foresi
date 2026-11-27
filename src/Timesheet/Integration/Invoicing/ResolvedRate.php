<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Integration\Invoicing;

use Foresi\Shared\Domain\Money;

/**
 * The hourly rate time is billed at, and the level that set it.
 */
final readonly class ResolvedRate
{
    public function __construct(
        public Money $rate,
        public RateSource $source,
    ) {
    }
}
