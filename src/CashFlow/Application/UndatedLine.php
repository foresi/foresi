<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\CashFlow\Contract\UndatedFlow;
use Foresi\Shared\Domain\Money;

/**
 * One undated flow in the forecast: as its source gave it, and in CZK.
 */
final readonly class UndatedLine
{
    public function __construct(
        public UndatedFlow $flow,
        public Money $amount,
    ) {
    }
}
