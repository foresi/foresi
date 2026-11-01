<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Shared\Domain\Currency;

/**
 * What a country decides about a document: its home currency and time zone, its
 * VAT rates by date, its legal notes, its supply codes, its payment code and its
 * electronic file. A country module implements this and is tagged automatically.
 */
interface InvoicingCountry
{
    public function supports(string $countryCode): bool;

    public function homeCurrency(): Currency;

    /**
     * Whether a total in the home currency may be rounded to a whole unit.
     */
    public function roundsTotalsToWholeUnits(): bool;

    public function timezone(): \DateTimeZone;

    /**
     * @throws \DomainException when the country has no such rate on that date
     */
    public function rate(VatRateKind $kind, \DateTimeImmutable $on): Percent;

    /**
     * The translation key of the statutory note a treatment prints, if it needs one.
     */
    public function noteKey(TaxTreatment $treatment): ?string;

    /**
     * The codes of the supply that the customer taxes, valid on that date.
     *
     * @return list<SupplyCode>
     */
    public function supplyCodes(\DateTimeImmutable $on): array;

    /**
     * Whether a document number can be used here, whatever else issues it.
     */
    public function acceptsNumber(string $number): bool;

    /**
     * The account as people of the country write it, if the IBAN is a domestic one.
     */
    public function domesticAccount(string $iban): ?string;

    public function paymentCode(IssuedDocument $document): ?string;

    public function electronicFile(IssuedDocument $document): ?ElectronicFile;
}
