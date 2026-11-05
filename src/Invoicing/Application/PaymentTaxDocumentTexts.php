<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;

/**
 * The text of the line of a tax document on payment, in the language of the
 * advance invoice it taxes a payment of.
 */
interface PaymentTaxDocumentTexts
{
    public function lineText(Invoice $advance): string;
}
