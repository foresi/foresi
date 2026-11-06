<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Shared\Domain\Money;

/**
 * What the documents a list shows add up to, on all its pages: how many, and their
 * base, VAT and total in the home currency, each document at its exchange rate.
 * A correction subtracts what it takes back. Split by how the lines are taxed.
 */
final readonly class InvoiceSums
{
    /**
     * @param list<InvoiceSumLine> $lines by treatment, the standard rates from the highest
     */
    public function __construct(public int $count, public Money $base, public Money $vat, public Money $total, public array $lines)
    {
    }
}
