<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Domain;

use Foresi\Catalog\Contract\BillingKind;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;

/**
 * What a line of a deal says, checked by Foresi\Sales\Application\DealLineRules
 * as the catalog checks a product: a recurring line has its unit and interval,
 * a one-off one has neither, and the cycles are positive or null (no end). The
 * quantity is a decimal with three places, such as "1.500".
 */
final readonly class DealLineTerms
{
    public function __construct(
        public string $name,
        public string $unit,
        public string $quantity,
        public ?Money $unitPrice,
        public BillingKind $billingKind,
        public ?RecurrenceUnit $recurrenceUnit,
        public ?int $interval,
        public ?int $cycles,
    ) {
    }
}
