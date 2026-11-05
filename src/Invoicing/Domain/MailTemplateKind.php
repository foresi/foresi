<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * What a mail template is for: one template per kind of document, and one for the
 * reminder of an overdue invoice. The values fit the column of 16 characters.
 */
enum MailTemplateKind: string
{
    case Invoice = 'invoice';
    case Reminder = 'reminder';
    case Advance = 'advance';
    case PaymentTaxDocument = 'tax_document';

    /**
     * The template a document is sent with.
     */
    public static function forDocument(DocumentKind $kind): self
    {
        return match ($kind) {
            DocumentKind::Invoice => self::Invoice,
            DocumentKind::Advance => self::Advance,
            DocumentKind::PaymentTaxDocument => self::PaymentTaxDocument,
        };
    }
}
