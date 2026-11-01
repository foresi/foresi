<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Form;

use App\Invoicing\Application\FirmInvoicingSettings;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\InvoicingCountry;
use App\Invoicing\Application\UnsupportedCountry;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\TotalRounding;
use App\Invoicing\Domain\VatRegime;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Currency;

/**
 * What a form works under: for a new document the firm as it is now, for a stored
 * one what was fixed at issue.
 */
final readonly class InvoiceFormContext
{
    private function __construct(
        public LegalEntity $firm,
        public ?Invoice $invoice,
        public InvoicingCountry $country,
        public VatRegime $regime,
        public TotalRounding $rounding,
        public Currency $currency,
    ) {
    }

    /**
     * @throws UnsupportedCountry
     */
    public static function new(LegalEntity $firm, InvoicingCountries $countries, FirmInvoicingSettings $settings): self
    {
        $country = $countries->for($firm->address()->countryCode);

        return new self($firm, null, $country, VatRegime::of($firm->vatStatus()), $settings->for($firm)->rounding(), $country->homeCurrency());
    }

    /**
     * @throws UnsupportedCountry
     */
    public static function stored(Invoice $invoice, InvoicingCountries $countries): self
    {
        return new self($invoice->firm(), $invoice, $countries->for($invoice->issuer()->address->countryCode), $invoice->vatRegime(), $invoice->rounding(), $invoice->currency());
    }

    public function isPayer(): bool
    {
        return VatRegime::Payer === $this->regime;
    }
}
