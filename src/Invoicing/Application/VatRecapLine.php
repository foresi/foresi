<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Shared\Domain\Money;

/**
 * The tax documents of a pack added up for one treatment and rate, in the home currency.
 */
final readonly class VatRecapLine
{
    public function __construct(
        public TaxTreatment $treatment,
        public ?Percent $rate,
        public Money $base,
        public Money $vat,
        public Money $gross,
    ) {
    }
}
