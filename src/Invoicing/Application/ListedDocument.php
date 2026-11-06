<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\RateSummary;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

/**
 * A document as the downloaded list has it: its row of the list, for what it has been
 * paid and what is left, with the customer, the tax point and the amounts per
 * treatment and rate, all in the currency of the document.
 */
final readonly class ListedDocument
{
    /**
     * @param list<RateSummary> $summaries
     */
    public function __construct(
        public InvoiceRow $row,
        public ?string $registrationNumber,
        public ?string $vatId,
        public ?\DateTimeImmutable $taxPointDate,
        public Currency $currency,
        public string $exchangeRate,
        public Money $net,
        public Money $vat,
        public Money $gross,
        public array $summaries,
    ) {
    }
}
