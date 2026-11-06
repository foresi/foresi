<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * The kind of a sales document. A document takes it from its series, so a series
 * holds documents of one kind only.
 *
 * An invoice is the tax document of a supply. An advance invoice asks for payment
 * before the supply and is no tax document; a tax document on payment taxes a
 * payment received on an advance, and is paid by that payment. A corrective tax
 * document corrects an issued document: its lines are the differences, so it is a
 * credit note when they are negative and a debit note when positive; its money
 * follows the document it corrects, so it asks for none itself. Every rule that
 * differs by kind asks the kind, never the series or the number.
 */
enum DocumentKind: string
{
    case Invoice = 'invoice';
    case Advance = 'advance';
    case PaymentTaxDocument = 'payment_tax_document';
    case CorrectionDocument = 'correction_document';

    /**
     * Whether the document asks for money: it is paired, reminded and overdue.
     */
    public function isPayable(): bool
    {
        return self::PaymentTaxDocument !== $this && self::CorrectionDocument !== $this;
    }

    /**
     * Whether the document is a tax document: it has a tax point and goes to the
     * accountant.
     */
    public function isTaxDocument(): bool
    {
        return self::Advance !== $this;
    }

    /**
     * Whether a person issues it from the form; a tax document on payment is
     * issued for a payment only.
     */
    public function isIssuedByPerson(): bool
    {
        return self::PaymentTaxDocument !== $this;
    }

    /**
     * Whether the document stands on its own, so it can be copied or repeated; a
     * tax document on payment and a correction always follow another document.
     */
    public function standsAlone(): bool
    {
        return self::Invoice === $this || self::Advance === $this;
    }

    /**
     * The name of the series a firm gets for this kind without setting one up; the
     * screens translate it.
     */
    public function standardSeriesName(): string
    {
        return match ($this) {
            self::Invoice => 'standard',
            self::Advance => 'standard_advance',
            self::PaymentTaxDocument => 'standard_payment_tax_document',
            self::CorrectionDocument => 'standard_correction_document',
        };
    }

    /**
     * The format of that series. The digit after the letter keeps the variable
     * symbol, which is the digits of the number, apart from an invoice's.
     */
    public function standardSeriesFormat(): string
    {
        return match ($this) {
            self::Invoice => '{YYYY}{NNNN}',
            self::Advance => 'Z9{YYYY}{NNNN}',
            self::PaymentTaxDocument => 'D8{YYYY}{NNNN}',
            self::CorrectionDocument => 'O7{YYYY}{NNNN}',
        };
    }

    /**
     * Whether a series name is the standard series of some kind, which the screens
     * translate instead of showing.
     */
    public static function isStandardSeriesName(string $name): bool
    {
        return array_any(self::cases(), static fn (self $kind): bool => $kind->standardSeriesName() === $name);
    }
}
