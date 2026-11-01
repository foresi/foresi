<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Shared\Domain\Money;

/**
 * A line as the user typed it. The rate comes from the kind, on the tax point.
 */
final readonly class LineInput
{
    public function __construct(
        public string $text,
        public Quantity $quantity,
        public string $unit,
        public Money $price,
        public PriceType $priceType,
        public TaxTreatment $treatment,
        public ?VatRateKind $rateKind,
        public ?string $supplyCode,
        public Percent $discount,
    ) {
    }
}
