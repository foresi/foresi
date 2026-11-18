<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Form;

use Foresi\Invoicing\Application\FirmInvoicingSettings;
use Foresi\Invoicing\Application\InvoicingCountries;
use Foresi\Invoicing\Application\UnsupportedCountry;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\InvoicingCountry;
use Foresi\Invoicing\Contract\TotalRounding;
use Foresi\Invoicing\Contract\VatRegime;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Shared\Domain\Currency;

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
        public DocumentKind $kind,
    ) {
    }

    /**
     * @throws UnsupportedCountry
     */
    public static function new(LegalEntity $firm, InvoicingCountries $countries, FirmInvoicingSettings $settings, DocumentKind $kind = DocumentKind::Invoice): self
    {
        $country = $countries->for($firm->address()->countryCode);

        return new self($firm, null, $country, VatRegime::of($firm->vatStatus()), $settings->for($firm)->rounding(), $country->homeCurrency(), $kind);
    }

    /**
     * @throws UnsupportedCountry
     */
    public static function stored(Invoice $invoice, InvoicingCountries $countries): self
    {
        return new self($invoice->firm(), $invoice, $countries->for($invoice->issuer()->address->countryCode), $invoice->vatRegime(), $invoice->rounding(), $invoice->currency(), $invoice->kind());
    }

    /**
     * Whether the document has a tax point: an advance invoice has none, and its
     * rates are those of its issue date.
     */
    public function hasTaxPoint(): bool
    {
        return $this->kind->isTaxDocument();
    }

    public function isPayer(): bool
    {
        return VatRegime::Payer === $this->regime;
    }
}
