<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The forecast of what the firm still expects to earn and pay (B1). Cash flow
 * fills it through this module's Integration/CashFlow directory (ADR 0033),
 * so without Cash flow there is none, and the year end says why. It leaves
 * out this module's own estimate and the levies themselves, so the estimate
 * never counts itself.
 */
#[AutoconfigureTag(self::TAG)]
interface ForecastAmountSource
{
    public const string TAG = 'foresi.taxes_and_contributions.forecast_source';

    /**
     * The amounts expected on the days from $from, today at the earliest, to
     * $to, both included. One still open from before today counts on $from.
     *
     * @throws \DomainException when the forecast refuses the read
     */
    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): ForecastedAmounts;
}
