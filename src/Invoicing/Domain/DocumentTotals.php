<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

use App\Shared\Domain\Money;

/**
 * What a document comes to. The summaries, net, VAT and gross are exact and never rounded;
 * the rounding of the total is a separate amount and the payable total is gross plus rounding.
 */
final readonly class DocumentTotals
{
    /**
     * @param list<RateSummary> $summaries
     */
    public function __construct(
        public array $summaries,
        public Money $net,
        public Money $vat,
        public Money $gross,
        public Money $rounding,
        public Money $payable,
    ) {
    }
}
