<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Contract;

use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;

/**
 * A product as it is now, for a module that copies it onto a line of its own,
 * such as a line of a deal: what it copies stays as it was when the product
 * later changes. A one-off product has no recurrence unit, interval or cycles;
 * a recurring one bills every $interval $recurrenceUnit, $cycles times or with
 * no end when $cycles is null.
 */
final readonly class ProductSnapshot
{
    public function __construct(
        public string $id,
        public string $name,
        public string $unit,
        public ?Money $unitPrice,
        public BillingKind $billingKind,
        public ?RecurrenceUnit $recurrenceUnit,
        public ?int $interval,
        public ?int $cycles,
        public bool $archived,
    ) {
    }
}
