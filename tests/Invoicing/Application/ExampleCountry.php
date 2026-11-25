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
use Foresi\Invoicing\Contract\SupplyCode;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Shared\Domain\Currency;

/**
 * Invoicing rules of a made-up country, for the rule checks that need no container:
 * crowns rounded to whole units, a base rate of 21 % and a reduced one of 12 %, a
 * second reduced rate of 10 % abolished on 15 November 2026, the supply codes 1 and
 * 3, and numbers accepted unless they hold an asterisk or surrounding whitespace.
 */
final readonly class ExampleCountry implements InvoicingCountry
{
    public const string SECOND_REDUCED_ABOLISHED = '2026-11-15';

    public function __construct(private string $code = 'CZ')
    {
    }

    public function supports(string $countryCode): bool
    {
        return $this->code === $countryCode;
    }

    public function homeCurrency(): Currency
    {
        return Currency::CZK;
    }

    public function roundsTotalsToWholeUnits(): bool
    {
        return true;
    }

    public function timezone(): \DateTimeZone
    {
        return new \DateTimeZone('Europe/Prague');
    }

    public function settlementLimit(Currency $currency): ?int
    {
        return null;
    }

    public function rate(VatRateKind $kind, \DateTimeImmutable $on): Percent
    {
        return match ($kind) {
            VatRateKind::Base => Percent::fromString('21'),
            VatRateKind::Reduced => Percent::fromString('12'),
            VatRateKind::SecondReduced => $on->format('Y-m-d') < self::SECOND_REDUCED_ABOLISHED ? Percent::fromString('10') : throw new \DomainException('The second reduced rate no longer exists.'),
        };
    }

    public function noteKey(TaxTreatment $treatment): ?string
    {
        return null;
    }

    public function supplyCodes(\DateTimeImmutable $on): array
    {
        return [new SupplyCode('1', 'supply.one'), new SupplyCode('3', 'supply.three')];
    }

    public function acceptsNumber(string $number): bool
    {
        return !str_contains($number, '*') && trim($number) === $number;
    }

    public function domesticAccount(string $iban): ?string
    {
        return null;
    }

    public function paymentCode(IssuedDocument $document): ?string
    {
        return null;
    }

    public function electronicFile(IssuedDocument $document): ?ElectronicFile
    {
        return null;
    }
}
