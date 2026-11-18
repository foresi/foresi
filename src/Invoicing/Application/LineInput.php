<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Domain\Percent;
use Foresi\Invoicing\Domain\PriceType;
use Foresi\Invoicing\Domain\Quantity;
use Foresi\Invoicing\Domain\TaxTreatment;
use Foresi\Invoicing\Domain\VatRateKind;
use Foresi\Shared\Domain\Money;

/**
 * A line as the user typed it. The rate comes from the kind, on the tax point.
 * A line another module prefilled carries its source: the key that module gave
 * it, which the form keeps with the row whatever is typed over it.
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
        public ?string $deducts = null,
        public ?string $source = null,
    ) {
    }
}
