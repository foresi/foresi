<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

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
     * The net and the VAT of a paid amount, in the ratio of the document and
     * without losing a minor unit: net + VAT is the amount paid, and a minor
     * unit left over goes to the net. A document with a gross of zero, or
     * whose net and VAT go opposite ways, has no ratio, and all of it is net.
     *
     * @return array{Money, Money} the net and the VAT, in the currency of the amount
     */
    public function split(Money $paid): array
    {
        $sign = $this->grossMinor < 0 ? -1 : 1;
        $net = $sign * $this->netMinor;
        $vat = $sign * ($this->grossMinor - $this->netMinor);
        if (0 === $this->grossMinor || $net < 0 || $vat < 0) {
            return [$paid, Money::ofMinor(0, $paid->currency())];
        }

        [$netPart, $vatPart] = $paid->allocate($net, $vat);

        return [$netPart, $vatPart];
    }
}
