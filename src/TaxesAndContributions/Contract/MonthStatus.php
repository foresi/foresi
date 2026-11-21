<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

use Foresi\Shared\Domain\Money;

/**
 * A month as the statuses on its first day make it: the activity (none,
 * secondary or main), whether health has no minimum and whether voluntary
 * sickness insurance is on, with the smallest social and health advances the
 * rules of that month ask.
 */
final readonly class MonthStatus
{
    public function __construct(
        public string $month,
        public string $activity,
        public bool $healthMinimumExempt,
        public bool $sicknessInsurance,
        public Money $minimumSocialAdvance,
        public Money $minimumHealthAdvance,
    ) {
    }
}
