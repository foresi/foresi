<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Domain;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Foresi\CashFlow\Contract\PlannedVatMode;
use Foresi\Shared\Domain\Money;

/**
 * The money of one occurrence of a planned item with its VAT stated: what
 * moves, the net and the VAT. An amount that includes the VAT moves as
 * stated and holds amount × rate / (100 + rate) of VAT; an amount without
 * it moves with amount × rate / 100 on top. The VAT is rounded half up to
 * the minor unit once.
 */
final readonly class PlannedItemVat
{
    private function __construct(public Money $flow, public Money $net, public Money $vat)
    {
    }

    /**
     * Null when the mode states no VAT.
     *
     * @param ?string $rate a percent such as "21" or "12.5"
     */
    public static function of(Money $amount, PlannedVatMode $mode, ?string $rate): ?self
    {
        if (PlannedVatMode::None === $mode || null === $rate) {
            return null;
        }
        $percent = BigDecimal::of($rate);
        $minor = BigDecimal::of($amount->minor());
        if (PlannedVatMode::Included === $mode) {
            $vat = Money::ofMinor($minor->multipliedBy($percent)->dividedBy($percent->plus(100), 0, RoundingMode::HalfUp)->toInt(), $amount->currency());

            return new self($amount, $amount->minus($vat), $vat);
        }
        $vat = Money::ofMinor($minor->multipliedBy($percent)->dividedBy(100, 0, RoundingMode::HalfUp)->toInt(), $amount->currency());

        return new self($amount->plus($vat), $amount, $vat);
    }
}
