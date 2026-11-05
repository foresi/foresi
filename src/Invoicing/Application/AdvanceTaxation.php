<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\TaxOnReceiptChoice;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRegime;
use App\Party\Domain\PartyKind;

/**
 * Whether VAT arises on the payments of an advance invoice, from what the advance
 * says and the choice a person made on it, in this order:
 *
 * 1. An issuer that is no VAT payer charges no VAT, whatever was chosen.
 * 2. A person may say the supply is not known yet, so nothing arises on receipt.
 * 3. A person may ask for the documents where the rules below say none.
 * 4. A customer who is a private person is owed no tax document on a payment.
 * 5. A supply taxed by the customer at home, every line a reverse charge to a
 *    customer of the issuer's own country, is taxed at supply, not on payment.
 *
 * Otherwise VAT arises on receipt. When every line is a reverse charge to a
 * customer of another country, the place of supply is abroad and the document is
 * due within the days after the end of the month of receipt.
 */
final readonly class AdvanceTaxation
{
    public function __construct(private InvoiceStore $store)
    {
    }

    /**
     * @throws \LogicException for a document that is no advance invoice
     */
    public function of(Invoice $advance): TaxOnReceipt
    {
        if (DocumentKind::Advance !== $advance->kind()) {
            throw new \LogicException('Only an advance invoice is taxed on receipt.');
        }
        if (VatRegime::Payer !== $advance->vatRegime()) {
            return TaxOnReceipt::none(TaxOnReceipt::NON_PAYER);
        }
        $lines = $this->store->lines($advance);
        $reverseCharge = [] !== $lines && array_all($lines, static fn (InvoiceLine $line): bool => TaxTreatment::ReverseCharge === $line->treatment());
        $crossBorder = $reverseCharge && !$this->isDomestic($advance);

        return match (true) {
            TaxOnReceiptChoice::Skip === $advance->taxOnReceipt() => TaxOnReceipt::none(TaxOnReceipt::UNKNOWN_SUPPLY),
            TaxOnReceiptChoice::Issue === $advance->taxOnReceipt() => TaxOnReceipt::arises($crossBorder),
            PartyKind::Person === $advance->customerParty()->kind() => TaxOnReceipt::none(TaxOnReceipt::CONSUMER),
            $reverseCharge && !$crossBorder => TaxOnReceipt::none(TaxOnReceipt::DOMESTIC_REVERSE_CHARGE),
            default => TaxOnReceipt::arises($crossBorder),
        };
    }

    /**
     * Whether the customer is registered for VAT in the issuer's country; without a
     * VAT number, where the customer's address is.
     */
    private function isDomestic(Invoice $advance): bool
    {
        $vatId = LineRules::vatIdOf($advance->customer()->vatId);
        $country = $vatId?->country() ?? $advance->customer()->address->countryCode;

        return $country === $advance->issuer()->address->countryCode;
    }
}
