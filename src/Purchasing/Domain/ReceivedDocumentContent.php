<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Domain;

use Foresi\Party\Domain\Party;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * The values of a received document a person or a reader of the file gives.
 * A draft may leave most of them out; recording checks they are complete. The
 * exchange rate of a CZK document is one, so it may be left out there. The
 * supply code is the code of the supply a domestic reverse charge reports in
 * the control statement (KH B.1); it is optional, as on issued documents.
 *
 * The lines split the bases of the VAT recap (DocumentLines): null keeps the
 * stored lines, an empty list removes them, a list replaces them. Kept or
 * given, they must add up to the recap saved with them.
 */
final readonly class ReceivedDocumentContent
{
    /**
     * @param list<VatRecapLine>                   $vatRecap
     * @param list<ReceivedDocumentLineInput>|null $lines
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
        public ?string $supplyCode = null,
        public ?array $lines = null,
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
