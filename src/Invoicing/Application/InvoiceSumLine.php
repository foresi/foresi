<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\TaxTreatment;
use App\Shared\Domain\Money;

/**
 * The lines of one treatment and rate within InvoiceSums, in the home currency.
 * Only a standard supply has a rate.
 */
final readonly class InvoiceSumLine
{
    public function __construct(public TaxTreatment $treatment, public ?Percent $rate, public Money $base, public Money $vat, public Money $total)
    {
    }
}
