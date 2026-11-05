<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;

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
}
