<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * A line with its amounts, as a rate summary takes it.
 */
final readonly class SummaryLine
{
    public function __construct(public TaxTreatment $treatment, public ?Percent $rate, public LineAmounts $amounts)
    {
    }
}
