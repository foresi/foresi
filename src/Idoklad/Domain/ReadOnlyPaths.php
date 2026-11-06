<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Domain;

/**
 * Every path of the iDoklad API the importer may read, as the API documents
 * them. Anything else is refused before a request is sent: the import never
 * writes to an account, never marks documents exported and never sends mail.
 */
final class ReadOnlyPaths
{
    /** GET endpoints of the API documentation; {id} is a number. */
    public const array TEMPLATES = [
        '/Contacts',
        '/Contacts/{id}',
        '/NumericSequences',
        '/NumericSequences/{id}',
        '/IssuedInvoices',
        '/IssuedInvoices/{id}',
        '/ProformaInvoices',
        '/ProformaInvoices/{id}',
        '/IssuedTaxDocuments',
        '/IssuedTaxDocuments/{id}',
        '/CreditNotes',
        '/CreditNotes/{id}',
        '/RecurringInvoices',
        '/RecurringInvoices/{id}',
        '/IssuedDocumentPayments',
        '/IssuedDocumentPayments/{id}',
        '/IssuedDocumentPayments/PaymentsForIssuedTaxDocument',
        '/Logs',
        '/Logs/{id}/{id}',
        '/Reports/IssuedInvoice/{id}/Pdf',
        '/Reports/ProformaInvoice/{id}/Pdf',
        '/Reports/IssuedTaxDocument/{id}/Pdf',
        '/Reports/CreditNote/{id}/Pdf',
    ];

    /**
     * @throws IdokladPathRefused
     */
    public static function check(string $method, string $path): void
    {
        if ('GET' !== $method) {
            throw new IdokladPathRefused(\sprintf('The import only reads from iDoklad; %s is refused.', $method));
        }
        $template = preg_replace('#(?<=/)[1-9][0-9]{0,18}(?=/|$)#D', '{id}', $path);
        if (!\in_array($template, self::TEMPLATES, true)) {
            throw new IdokladPathRefused('The import does not read this iDoklad path.');
        }
    }
}
