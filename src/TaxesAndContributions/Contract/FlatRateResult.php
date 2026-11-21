<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * The flat-rate regime of a year, beside the expense methods: the months it
 * covers with their bands and amounts, and its parts together. In those
 * months the band's amount replaces social, health and income tax, and no
 * credit, deduction or child benefit applies. It is the same for every
 * expense method.
 */
final readonly class FlatRateResult
{
    public Money $incomeTax;
    public Money $social;
    public Money $health;
    public Money $total;

    /**
     * @param non-empty-list<FlatRateMonth> $months oldest first
     */
    public function __construct(public array $months)
    {
        $zero = Money::ofMinor(0, Currency::CZK);
        [$tax, $social, $health] = [$zero, $zero, $zero];
        foreach ($months as $month) {
            $tax = $tax->plus($month->incomeTax);
            $social = $social->plus($month->social);
            $health = $health->plus($month->health);
        }
        $this->incomeTax = $tax;
        $this->social = $social;
        $this->health = $health;
        $this->total = $tax->plus($social)->plus($health);
    }
}
