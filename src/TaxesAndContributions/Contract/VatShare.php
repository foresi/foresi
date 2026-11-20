<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Foresi\Shared\Domain\Money;

/**
 * What part of a document's gross is its net (B3): every payment of the
 * document, and its open remainder, splits in this ratio. The net is the gross
 * less the VAT that can be deducted or is owed to the state; a document whose
 * VAT stays a cost has its net equal to its gross.
 */
final readonly class VatShare
{
    public function __construct(
        public int $netMinor,
        public int $grossMinor,
    ) {
    }

    /**
     * The net and the VAT of a paid amount: the net is rounded half up to the
     * minor unit and the VAT takes the rest, so net + VAT is the amount paid.
     * A document with a gross of zero has no ratio, and all of it is net.
     *
     * @return array{Money, Money} the net and the VAT, in the currency of the amount
     */
    public function split(Money $paid): array
    {
        if (0 === $this->grossMinor) {
            return [$paid, Money::ofMinor(0, $paid->currency())];
        }
        $net = BigInteger::of($paid->minor())->multipliedBy($this->netMinor)->dividedBy($this->grossMinor, RoundingMode::HalfUp)->toInt();

        return [Money::ofMinor($net, $paid->currency()), Money::ofMinor($paid->minor() - $net, $paid->currency())];
    }
}
