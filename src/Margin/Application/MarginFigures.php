<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * Revenue, costs and margin (revenue minus costs) of one cell, one row, one
 * month or the whole table, in CZK whole hellers.
 */
final readonly class MarginFigures
{
    public Money $margin;

    public function __construct(public Money $revenue, public Money $costs)
    {
        $this->margin = $revenue->minus($costs);
    }

    public static function none(): self
    {
        $zero = Money::ofMinor(0, Currency::CZK);

        return new self($zero, $zero);
    }

    public function plus(self $other): self
    {
        return new self($this->revenue->plus($other->revenue), $this->costs->plus($other->costs));
    }

    public function isEmpty(): bool
    {
        return $this->revenue->isZero() && $this->costs->isZero();
    }
}
