<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\Shared\Domain\Money;

/**
 * What the forecast expects without a day (the "No date" column), in CZK. It
 * is outside every balance: nothing here moves the opening, the closing, the
 * lowest point or the minimum-balance warning.
 */
final readonly class UndatedForecast
{
    /**
     * @param array<string, Money> $inflows             by category
     * @param array<string, Money> $outflows            by category
     * @param list<UndatedLine>    $lines               those the caller may read, the largest first
     * @param list<HiddenTotal>    $hidden              the lines of the sources the caller may not read, summed
     * @param array<string, Money> $inflowsByCertainty  money in by how sure it is, the hidden lines among them
     * @param array<string, Money> $outflowsByCertainty money out by how sure it is, the hidden lines among them
     */
    public function __construct(
        public array $inflows = [],
        public array $outflows = [],
        public array $lines = [],
        public array $hidden = [],
        public array $inflowsByCertainty = [],
        public array $outflowsByCertainty = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->lines && [] === $this->hidden;
    }

    /**
     * How many amounts it holds, those the caller may read and the hidden ones.
     */
    public function count(): int
    {
        return \count($this->lines) + array_sum(array_map(static fn (HiddenTotal $hidden): int => $hidden->count, $this->hidden));
    }
}
