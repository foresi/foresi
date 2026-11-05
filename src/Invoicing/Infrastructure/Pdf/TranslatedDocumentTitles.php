<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Pdf;

use App\Invoicing\Application\DocumentTitles;
use App\Invoicing\Application\IssuedDocument;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\VatRegime;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The title from the PDF's own translations, so the mail names the document as
 * its PDF does.
 */
final readonly class TranslatedDocumentTitles implements DocumentTitles
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function of(IssuedDocument $document): string
    {
        return $this->translator->trans(self::keyOf($document), locale: $document->language->value);
    }

    /**
     * The translation key of the title: by kind, and an invoice of a payer is a tax document.
     */
    public static function keyOf(IssuedDocument $document): string
    {
        return match ($document->kind) {
            DocumentKind::Advance => 'invoice.pdf.title_advance',
            DocumentKind::PaymentTaxDocument => 'invoice.pdf.title_payment_tax_document',
            DocumentKind::CorrectionDocument => 'invoice.pdf.title_correction_document',
            DocumentKind::Invoice => VatRegime::Payer === $document->regime ? 'invoice.pdf.title_tax' : 'invoice.pdf.title',
        };
    }
}
