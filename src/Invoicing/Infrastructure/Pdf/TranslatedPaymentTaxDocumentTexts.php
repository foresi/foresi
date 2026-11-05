<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Pdf;

use App\Invoicing\Application\PaymentTaxDocumentTexts;
use App\Invoicing\Domain\Invoice;
use App\Party\Domain\DocumentLanguage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * From the PDF's own translations, as iDoklad words its line: the received payment
 * of the advance invoice, named by its variable symbol and number.
 */
final readonly class TranslatedPaymentTaxDocumentTexts implements PaymentTaxDocumentTexts
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function lineText(Invoice $advance): string
    {
        return $this->translator->trans('invoice.pdf.tax_document_line', ['number' => $advance->number(), 'symbol' => $advance->variableSymbol()], locale: $advance->language()->value);
    }

    public function deductionText(Invoice $advance, ?Invoice $taxDocument, DocumentLanguage $language): string
    {
        return $this->translator->trans('invoice.pdf.deduction_line', ['symbol' => $advance->variableSymbol(), 'number' => $advance->number(), 'document' => $taxDocument?->number() ?? 'none'], locale: $language->value);
    }

    public function cancelledReason(Invoice $document): string
    {
        return $this->translator->trans('tax_document.cancelled_reason', locale: $document->language()->value);
    }
}
