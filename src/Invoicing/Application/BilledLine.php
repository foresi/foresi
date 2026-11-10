<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Quantity;
use App\Shared\Domain\Money;

/**
 * A line of work to bill: its text, quantity, unit and price, and the source
 * the line keeps on the form.
 */
final readonly class BilledLine
{
    public function __construct(
        public string $text,
        public Quantity $quantity,
        public string $unit,
        public Money $price,
        public string $source,
    ) {
    }
}
