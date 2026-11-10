<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Domain;

use App\Party\Domain\Party;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

/**
 * The values of a received document a person or a reader of the file gives.
 * A draft may leave most of them out; recording checks they are complete. The
 * exchange rate of a CZK document is one, so it may be left out there.
 */
final readonly class ReceivedDocumentContent
{
    /**
     * @param list<VatRecapLine> $vatRecap
     */
    public function __construct(
        public ReceivedDocumentKind $kind,
        public PurchaseTaxTreatment $treatment,
        public ?Party $supplierParty,
        public SupplierSnapshot $supplier,
        public string $supplierNumber,
        public string $variableSymbol,
        public ?\DateTimeImmutable $issueDate,
        public ?\DateTimeImmutable $taxPointDate,
        public ?\DateTimeImmutable $dueDate,
        public ?\DateTimeImmutable $receivedDate,
        public Currency $currency,
        public ?Money $total,
        public ?ExchangeRate $exchangeRate,
        public ?\DateTimeImmutable $exchangeRateDate,
        public array $vatRecap,
        public ?string $originalDocumentId,
    ) {
    }

    /**
     * What an uploaded file starts with before anyone reads it.
     */
    public static function blank(?\DateTimeImmutable $receivedDate = null): self
    {
        return new self(ReceivedDocumentKind::TaxInvoice, PurchaseTaxTreatment::Domestic, null, SupplierSnapshot::none(), '', '', null, null, null, $receivedDate, Currency::CZK, null, null, null, [], null);
    }
}
