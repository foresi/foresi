<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Country\CzechRepublic\Invoicing;

use App\Country\CzechRepublic\Party\CzechBankAccount;
use App\Invoicing\Application\ElectronicFile;
use App\Invoicing\Application\InvoicingCountry;
use App\Invoicing\Application\IssuedDocument;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Shared\Domain\Currency;

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

    public function paymentCode(IssuedDocument $document): ?string
    {
        return $this->qr->for($document);
    }

    public function electronicFile(IssuedDocument $document): ElectronicFile
    {
        return new ElectronicFile('invoice.isdoc', $this->isdoc->write($document), 'text/xml');
    }
}
