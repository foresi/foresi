<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Application;

use App\Invoicing\Domain\Quantity;
use App\Shared\Domain\Money;

/**
 * A line proposed for the invoice, under the key its row keeps on the form.
 */
final readonly class SelectedLine
{
    /**
     * @param list<SelectedEntry> $entries
     */
    public function __construct(
        public string $key,
        public string $text,
        public Quantity $hours,
        public string $unit,
        public Money $rate,
        public array $entries,
    ) {
    }
}
