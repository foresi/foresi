<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\Invoicing\CzechRepublic;

use Foresi\Country\CzechRepublic\Party\CzechBankAccount;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\ElectronicFile;
use Foresi\Invoicing\Contract\InvoicingCountry;
use Foresi\Invoicing\Contract\IssuedDocument;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Shared\Domain\Currency;

/**
 * The invoicing rules of Czechia.
 */
final readonly class CzechInvoicing implements InvoicingCountry
{
    public function __construct(private CzechVatRates $rates, private CzechSupplyCodes $supplyCodes, private IsdocWriter $isdoc, private QrPlatba $qr)
    {
    }

    public function supports(string $countryCode): bool
    {
        return 'CZ' === $countryCode;
    }

    public function homeCurrency(): Currency
    {
        return Currency::CZK;
    }

    /**
     * VAT Act §36(5): a total in crowns may be rounded to a whole crown, outside the base and the tax.
     */
    public function roundsTotalsToWholeUnits(): bool
    {
        return true;
    }

    /**
     * Up to 99 haléř in crowns, the coins nobody pays by transfer. Another
     * currency has no such custom.
     */
    public function settlementLimit(Currency $currency): ?int
    {
        return Currency::CZK === $currency ? 99 : null;
    }

    public function timezone(): \DateTimeZone
    {
        return new \DateTimeZone('Europe/Prague');
    }

    public function rate(VatRateKind $kind, \DateTimeImmutable $on): Percent
    {
        return $this->rates->rate($kind, $on);
    }

    public function noteKey(TaxTreatment $treatment): ?string
    {
        return match ($treatment) {
            TaxTreatment::ReverseCharge => 'invoice.pdf.note.reverse_charge',
            TaxTreatment::Exempt => 'invoice.pdf.note.exempt',
            default => null,
        };
    }

    public function supplyCodes(\DateTimeImmutable $on): array
    {
        return $this->supplyCodes->on($on);
    }

    public function acceptsNumber(string $number): bool
    {
        return QrPlatba::canCarry($number);
    }

    public function domesticAccount(string $iban): ?string
    {
        $parts = CzechBankAccount::partsOf($iban);

        return null === $parts ? null : $parts['number'].'/'.$parts['bankCode'];
    }

    /**
     * None on a document that asks for nothing: a tax document on payment is paid,
     * and a credit note lowers what is owed. A debit note asks for what it adds.
     */
    public function paymentCode(IssuedDocument $document): ?string
    {
        return $document->asksForPayment() ? $this->qr->for($document) : null;
    }

    /**
     * An invoice and a corrective tax document carry ISDOC: an advance invoice and a
     * tax document on payment go without, as iDoklad sends them.
     */
    public function electronicFile(IssuedDocument $document): ?ElectronicFile
    {
        return \in_array($document->kind, [DocumentKind::Invoice, DocumentKind::CorrectionDocument], true) ? new ElectronicFile('invoice.isdoc', $this->isdoc->write($document), 'text/xml') : null;
    }
}
