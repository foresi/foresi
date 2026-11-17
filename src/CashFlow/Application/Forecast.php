<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Application;

use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Money;

/**
 * The cash-flow forecast of a firm, in CZK at today's rates, computed on read.
 * It is incomplete when a source, a balance source or a rate failed, or an
 * account has no balance; the lists say which.
 */
final readonly class Forecast
{
    /**
     * @param list<AccountOpening> $accounts
     * @param list<PeriodForecast> $periods
     * @param list<string>         $unavailableSources the keys of the sources that failed
     * @param list<string>         $unavailableRates   the currencies without a rate today
     */
    public function __construct(
        public LegalEntity $firm,
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
    ) {
    }

    public function isIncomplete(): bool
    {
        return [] !== $this->unavailableSources || [] !== $this->unavailableRates
            || array_any($this->accounts, static fn (AccountOpening $account): bool => !$account->amount instanceof Money);
    }
}
