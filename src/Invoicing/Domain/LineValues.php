<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

use App\Shared\Domain\Money;

/**
 * What the user typed on a line: the figures the calculation starts from.
 */
final readonly class LineValues
{
    public function __construct(
        public Money $price,
        public Quantity $quantity,
        public PriceType $priceType,
        public TaxTreatment $treatment,
        public ?Percent $rate,
        public Percent $discount,
    ) {
    }
}
