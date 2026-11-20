<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

use Foresi\Shared\Domain\Money;

/**
 * The amounts of one source and one kind paid in one month, in CZK: the
 * gross, its net and VAT, and what the sole trader's records count, which is
 * the gross before the day the owner became a VAT payer and the net from it.
 */
final readonly class AmountSum
{
    public function __construct(
        public string $month,
        public AmountKind $kind,
        public string $source,
        public Money $gross,
        public Money $net,
        public Money $vat,
        public Money $counted,
    ) {
    }
}
