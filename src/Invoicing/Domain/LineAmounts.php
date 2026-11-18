<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Domain;

use Foresi\Shared\Domain\Money;

/**
 * What a line comes to: the net per unit and the net, VAT and gross of the whole line.
 */
final readonly class LineAmounts
{
    public function __construct(
        public Money $unitNet,
        public Money $net,
        public Money $vat,
        public Money $gross,
    ) {
    }
}
