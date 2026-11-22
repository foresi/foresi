<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Contract;

use Foresi\Shared\Domain\Money;

/**
 * The VAT one document gives the firm's VAT return, in CZK: the output VAT
 * it owes and the input VAT it deducts. It counts in the VAT period that
 * holds $countsOn (the tax point of an issued document, the first day of the
 * deduction month of a received one), and only when the firm is a payer on
 * its tax point. A document whose VAT has no CZK amount counts zero and says
 * so with $missingCzk.
 */
final readonly class DocumentVat
{
    public function __construct(
        /** The key of the source, such as "invoicing.issued_vat". */
        public string $source,
        public VatSubject $subject,
        /** What a person knows the document by, such as its number. */
        public string $label,
        public \DateTimeImmutable $countsOn,
        public \DateTimeImmutable $taxPointOn,
        public Money $output,
        public Money $input,
        public bool $missingCzk = false,
    ) {
    }
}
