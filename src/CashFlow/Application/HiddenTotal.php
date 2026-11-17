<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Application;

use App\CashFlow\Contract\FlowCategory;
use App\CashFlow\Contract\FlowDirection;
use App\Shared\Domain\Money;

/**
 * What the lines of one source the caller may not read add up to in a period,
 * by direction and category, in CZK: the totals without the documents (C15).
 */
final readonly class HiddenTotal
{
    public function __construct(
        public string $source,
        public FlowDirection $direction,
        public FlowCategory $category,
        public Money $amount,
        public int $count,
    ) {
    }
}
