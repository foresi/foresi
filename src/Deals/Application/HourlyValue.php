<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application;

use Foresi\Shared\Domain\Money;

/**
 * One hourly line of a deal: its name, its rate per hour and, when hours are expected,
 * those hours and the amount they make (rate times hours, rounded half-up).
 * Without expected hours both are null: not estimated.
 */
final readonly class HourlyValue
{
    public function __construct(
        public string $name,
        public Money $rate,
        public ?string $expectedHours,
        public ?Money $expectedAmount,
    ) {
    }
}
