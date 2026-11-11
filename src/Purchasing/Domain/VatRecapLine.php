<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Domain;

use App\Shared\Domain\Money;

/**
 * One rate of the VAT recap as typed or read from the document: the base and
 * the VAT the supplier charged, both in the document's currency, and the VAT in
 * CZK when a document in another currency prints it.
 */
final readonly class VatRecapLine
{
    public function __construct(
        public VatRate $rate,
        public Money $base,
        public Money $vat,
        public ?Money $statedVatCzk = null,
    ) {
    }
}
