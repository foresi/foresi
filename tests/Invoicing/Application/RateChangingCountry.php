<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Contract\ElectronicFile;
use Foresi\Invoicing\Contract\InvoicingCountry;
use Foresi\Invoicing\Contract\IssuedDocument;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Shared\Domain\Currency;

/**
 * The Czech rules, with the base rate at 23 % from 1 January 2027.
 */
final readonly class RateChangingCountry implements InvoicingCountry
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

    public function settlementLimit(Currency $currency): ?int
    {
        return $this->inner->settlementLimit($currency);
    }

    public function rate(VatRateKind $kind, \DateTimeImmutable $on): Percent
    {
        return VatRateKind::Base === $kind && $on->format('Y-m-d') >= '2027-01-01' ? Percent::fromString('23') : $this->inner->rate($kind, $on);
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
