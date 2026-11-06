<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Shared\Domain\Money;

/**
 * What the tax documents of a month, by their tax point, add up to in the home
 * currency: invoices, tax documents on payment and corrections, which subtract
 * what they take back.
 *
 * @param string $month YYYY-MM
 */
final readonly class MonthInvoiced
{
    public function __construct(public string $month, public int $count, public Money $base, public Money $vat, public Money $total)
    {
    }
}
