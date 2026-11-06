<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Shared\Domain\Money;

/**
 * One document still to pay: what is left in the home currency, at the exchange
 * rate of the document, and how many days past its due date it is today in the
 * country that issued it; zero when it is not due yet.
 */
final readonly class Receivable
{
    public function __construct(public string $customerName, public Money $remaining, public int $daysOverdue)
    {
    }
}
