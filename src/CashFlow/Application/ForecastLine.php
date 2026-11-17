<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Application;

use App\CashFlow\Contract\ExpectedFlow;
use App\CashFlow\Contract\FlowDirection;
use App\Shared\Domain\Money;

/**
 * One expected flow in the forecast: as its source gave it, in CZK, and the
 * day it is counted on. An overdue flow is counted on the first day.
 */
final readonly class ForecastLine
{
    public function __construct(
        public ExpectedFlow $flow,
        public Money $amount,
        public \DateTimeImmutable $countedOn,
    ) {
    }

    /**
     * The amount with its sign: positive in, negative out.
     */
    public function signed(): Money
    {
        return FlowDirection::In === $this->flow->direction ? $this->amount : Money::ofMinor(-$this->amount->minor(), $this->amount->currency());
    }
}
