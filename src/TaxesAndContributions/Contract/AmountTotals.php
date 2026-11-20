<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

use Foresi\Shared\Domain\Money;

/**
 * The gross, net, VAT and counted amounts of one kind over the whole range, in CZK.
 */
final readonly class AmountTotals
{
    public function __construct(
        public Money $gross,
        public Money $net,
        public Money $vat,
        public Money $counted,
    ) {
    }
}
