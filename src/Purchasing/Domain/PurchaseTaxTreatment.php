<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Domain;

/**
 * How the VAT of a received document is treated, which decides where it goes in
 * the VAT reports (ADR 0028). Domestic VAT charged by the supplier is deducted.
 * In the three self-assessed treatments the supplier charges no VAT: the firm
 * declares the tax itself and deducts it in the same return. A document that is
 * no VAT document carries no VAT, and non-deductible VAT is paid but never
 * deducted (entertainment, private use).
 */
enum PurchaseTaxTreatment: string
{
    case Domestic = 'domestic';
    case DomesticReverseCharge = 'domestic_reverse_charge';
    case EuService = 'eu_service';
    case NonEuService = 'non_eu_service';
    case NotVatDocument = 'not_vat_document';
    case NonDeductible = 'non_deductible';

    /**
     * Whether the firm declares the tax itself, computed from the base.
     */
    public function isSelfAssessed(): bool
    {
        return self::DomesticReverseCharge === $this || self::EuService === $this || self::NonEuService === $this;
    }

    /**
     * Whether the VAT of the document is deducted.
     */
    public function isDeductible(): bool
    {
        return self::Domestic === $this || $this->isSelfAssessed();
    }

    /**
     * Whether the document may state its own VAT in CZK: only VAT the supplier
     * charged has an amount printed on the document.
     */
    public function takesStatedVat(): bool
    {
        return self::Domestic === $this || self::NonDeductible === $this;
    }
}
