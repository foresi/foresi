<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Country\CzechRepublic\Invoicing;

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
    public function __construct(private CzechVatRates $rates, private CzechSupplyCodes $supplyCodes)
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
        return TaxTreatment::ReverseCharge === $treatment ? 'invoice.note.reverse_charge' : null;
    }

    public function supplyCodes(\DateTimeImmutable $on): array
    {
        return $this->supplyCodes->on($on);
    }

    /**
     * Filled by the QR payment step.
     */
    public function paymentCode(IssuedDocument $document): ?string
    {
        throw new \LogicException('The Czech payment code is not built yet.');
    }

    /**
     * Filled by the ISDOC step.
     */
    public function electronicFile(IssuedDocument $document): ?ElectronicFile
    {
        throw new \LogicException('The Czech electronic file is not built yet.');
    }
}
