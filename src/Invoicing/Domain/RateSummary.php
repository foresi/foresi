<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

use App\Shared\Domain\Money;

/**
 * The lines of one treatment and rate added up. A treatment without a rate has none here.
 */
final readonly class RateSummary
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
