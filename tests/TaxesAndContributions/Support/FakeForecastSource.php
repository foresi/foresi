<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Support;

use Foresi\TaxesAndContributions\Contract\ForecastAmount;
use Foresi\TaxesAndContributions\Contract\ForecastAmountSource;
use Foresi\TaxesAndContributions\Contract\ForecastedAmounts;

/**
 * A forecast that expects what the test gives it, refuses when told to, and
 * remembers the days it was asked for.
 */
final class FakeForecastSource implements ForecastAmountSource
{
    /** @var list<array{string, string}> */
    public array $asked = [];

    /**
     * @param list<ForecastAmount> $amounts
     * @param list<string>         $unavailable
     */
    public function __construct(private readonly array $amounts = [], private readonly array $unavailable = [], private readonly bool $refuses = false)
    {
    }

    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): ForecastedAmounts
    {
        $this->asked[] = [$from->format('Y-m-d'), $to->format('Y-m-d')];
        if ($this->refuses) {
            throw new \DomainException('The forecast is refused.');
        }

        return new ForecastedAmounts($this->amounts, $this->unavailable, [], [] !== $this->unavailable);
    }
}
