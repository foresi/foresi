<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Contract\Event;

/**
 * The kind of a document, as an event states it.
 */
enum DocumentKindValue: string
{
    case Invoice = 'invoice';
    case Advance = 'advance';
    case PaymentTaxDocument = 'payment_tax_document';
    case CorrectionDocument = 'correction_document';
}
