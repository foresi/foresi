<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;
use App\Party\Domain\DocumentLanguage;

/**
 * The texts a tax document on payment gets, in the language of the document: the
 * one of the advance invoice it taxes a payment of.
 */
interface PaymentTaxDocumentTexts
{
    public function lineText(Invoice $advance): string;

    /**
     * Why a tax document went to the trash with its cancelled payment.
     */
    public function cancelledReason(Invoice $document): string;

    /**
     * Why a tax document went to the trash when its payment went back before the
     * customer got it.
     */
    public function refundedReason(Invoice $document): string;

    /**
     * The reason on the corrective tax document of a tax document on payment whose
     * payment was cancelled, or refunded.
     */
    public function correctionReason(Invoice $document, bool $refunded): string;

    /**
     * The text of a line of a final invoice that deducts a tax document on payment,
     * or an advance invoice on which no VAT arose, in the final invoice's language.
     */
    public function deductionText(Invoice $advance, ?Invoice $taxDocument, DocumentLanguage $language): string;
}
