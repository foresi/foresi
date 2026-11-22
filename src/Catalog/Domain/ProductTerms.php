<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Domain;

use Foresi\Catalog\Contract\BillingKind;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;

/**
 * What a product says, checked by Foresi\Catalog\Application\ProductRules: a
 * recurring product has its unit and interval, a one-off one has neither, and
 * the cycles are positive or null (open-ended).
 */
final readonly class ProductTerms
{
    public function __construct(
        public string $name,
        public string $description,
        public string $unit,
        public ?Money $unitPrice,
        public BillingKind $billingKind,
        public ?RecurrenceUnit $recurrenceUnit,
        public ?int $interval,
        public ?int $cycles,
    ) {
    }
}
