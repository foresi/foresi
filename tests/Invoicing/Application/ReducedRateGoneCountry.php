<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\ElectronicFile;
use App\Invoicing\Application\InvoicingCountry;
use App\Invoicing\Application\IssuedDocument;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Shared\Domain\Currency;

/**
 * The Czech rules, with no reduced rate from 1 January 2027, as when a country
 * drops a kind of rate.
 */
final readonly class ReducedRateGoneCountry implements InvoicingCountry
{
    public function __construct(private InvoicingCountry $inner)
    {
    }

    public function supports(string $countryCode): bool
    {
        return $this->inner->supports($countryCode);
    }

    public function homeCurrency(): Currency
    {
        return $this->inner->homeCurrency();
    }

    public function roundsTotalsToWholeUnits(): bool
    {
        return $this->inner->roundsTotalsToWholeUnits();
    }

    public function timezone(): \DateTimeZone
    {
        return $this->inner->timezone();
    }

    public function rate(VatRateKind $kind, \DateTimeImmutable $on): Percent
    {
        if (VatRateKind::Reduced === $kind && $on->format('Y-m-d') >= '2027-01-01') {
            throw new \DomainException('No reduced VAT rate is defined from 2027.');
        }

        return $this->inner->rate($kind, $on);
    }

    public function noteKey(TaxTreatment $treatment): ?string
    {
        return $this->inner->noteKey($treatment);
    }

    public function supplyCodes(\DateTimeImmutable $on): array
    {
        return $this->inner->supplyCodes($on);
    }

    public function acceptsNumber(string $number): bool
    {
        return $this->inner->acceptsNumber($number);
    }

    public function domesticAccount(string $iban): ?string
    {
        return $this->inner->domesticAccount($iban);
    }

    public function paymentCode(IssuedDocument $document): ?string
    {
        return $this->inner->paymentCode($document);
    }

    public function electronicFile(IssuedDocument $document): ?ElectronicFile
    {
        return $this->inner->electronicFile($document);
    }
}
