<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Application;

use Foresi\Invoicing\Domain\Quantity;
use Foresi\Shared\Domain\Money;

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
