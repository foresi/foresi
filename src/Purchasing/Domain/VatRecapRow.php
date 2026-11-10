<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Domain;

use App\Shared\Domain\Money;

/**
 * One rate of the stored VAT recap: the line as given and its amounts in CZK.
 * Both CZK amounts are null while a document in another currency has no
 * exchange rate, the VAT in CZK not when the document states it.
 */
final readonly class VatRecapRow
{
    public function __construct(
        public VatRate $rate,
        public Money $base,
        public Money $vat,
        public ?Money $statedVatCzk,
        public ?Money $baseCzk,
        public ?Money $vatCzk,
    ) {
    }
}
