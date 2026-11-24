<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\Shared\Domain\Money;

/**
 * The cash-flow forecast, in CZK at today's rates, computed on read.
 * It is incomplete when a source, a balance source or a rate failed, or an
 * account has no balance; the lists say which.
 */
final readonly class Forecast
{
    /**
     * @param list<AccountOpening>     $accounts
     * @param list<PeriodForecast>     $periods
     * @param list<string>             $unavailableSources the keys of the sources that failed
     * @param list<string>             $unavailableRates   the currencies without a rate today
     * @param list<string>             $restrictedSources  the keys of the sources whose documents the caller may not read: in the totals, not as items
     * @param list<FlowCertainty>|null $certainties        the certainties counted; null when every flow counts
     * @param string                   $text               the text the flows counted hold in their labels; empty when every flow counts
     */
    public function __construct(
        public \DateTimeImmutable $today,
        public \DateTimeImmutable $from,
        public Money $opening,
        public array $accounts,
        public ?\DateTimeImmutable $balanceAsOf,
        public bool $stale,
        public array $periods,
        public Money $lowest,
        public \DateTimeImmutable $lowestOn,
        public ?Money $minimum,
        public ?\DateTimeImmutable $shortfallOn,
        public array $unavailableSources,
        public array $unavailableRates,
        public array $restrictedSources = [],
        public ?array $certainties = null,
        public string $text = '',
    ) {
    }

    /**
     * Whether a certainty filter or a text leaves flows out.
     */
    public function isFiltered(): bool
    {
        return '' !== $this->text || (null !== $this->certainties && \count($this->certainties) < \count(FlowCertainty::cases()));
    }

    public function isIncomplete(): bool
    {
        return [] !== $this->unavailableSources || [] !== $this->unavailableRates
            || array_any($this->accounts, static fn (AccountOpening $account): bool => !$account->amount instanceof Money);
    }
}
