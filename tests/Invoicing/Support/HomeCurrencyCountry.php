<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Support;

use Foresi\Invoicing\Contract\ElectronicFile;
use Foresi\Invoicing\Contract\InvoicingCountry;
use Foresi\Invoicing\Contract\IssuedDocument;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Shared\Domain\Currency;

/**
 * The Czech rules with another home currency, so that an invoice of the firm is
 * issued in a currency with 0 or 3 minor units. It never rounds a total to a
 * whole unit, which is a custom of the crown alone. Set it in the container before
 * anything has used the country:
 * $container->set(CzechInvoicing::class, new HomeCurrencyCountry($czech, Currency::of('JPY'))).
 */
final readonly class HomeCurrencyCountry implements InvoicingCountry
{
    public function __construct(private InvoicingCountry $inner, private Currency $home)
    {
    }

    public function supports(string $countryCode): bool
    {
        return $this->inner->supports($countryCode);
    }

    public function homeCurrency(): Currency
    {
        return $this->home;
    }

    public function roundsTotalsToWholeUnits(): bool
    {
        return false;
    }

    public function timezone(): \DateTimeZone
    {
        return $this->inner->timezone();
    }

    public function settlementLimit(Currency $currency): ?int
    {
        return null;
    }

    public function rate(VatRateKind $kind, \DateTimeImmutable $on): Percent
    {
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
