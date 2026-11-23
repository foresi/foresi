<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Domain;

use Foresi\CashFlow\Contract\PlannedVatMode;
use Foresi\Shared\Domain\Money;

/**
 * The money of one occurrence of a planned item with its VAT stated: what
 * moves, the net and the VAT. An amount that includes the VAT moves as
 * stated and holds amount × rate / (100 + rate) of VAT; an amount without
 * it moves with amount × rate / 100 on top. A reverse charge moves the
 * amount as stated, its net, and self-assesses amount × rate / 100 of VAT
 * the amount does not hold. The VAT is rounded half up to the minor unit once.
 */
final readonly class PlannedItemVat
{
    /**
     * @param bool $selfAssessed the firm as the recipient self-assesses the VAT, which the flow does not hold
     */
    private function __construct(public Money $flow, public Money $net, public Money $vat, public bool $selfAssessed = false)
    {
    }

    /**
     * The VAT the flow holds: the VAT, but zero for a reverse charge.
     */
    public function held(): Money
    {
        return $this->selfAssessed ? Money::ofMinor(0, $this->vat->currency()) : $this->vat;
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
        // A rate has at most two decimals, so in hundredths it is whole and the product is exact.
        $hundredths = (int) round((float) $rate * 100);
        if (PlannedVatMode::Included === $mode) {
            $vat = $amount->multipliedBy((string) $hundredths)->dividedBy((string) (10_000 + $hundredths));

            return new self($amount, $amount->minus($vat), $vat);
        }
        $vat = $amount->multipliedBy((string) $hundredths)->dividedBy('10000');
        if (PlannedVatMode::ReverseCharge === $mode) {
            return new self($amount, $amount, $vat, true);
        }

        return new self($amount->plus($vat), $amount, $vat);
    }
}
