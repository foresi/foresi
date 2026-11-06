<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Domain;

/**
 * The lists of an iDoklad account a pull copies, by their API path. The lists
 * of documents carry their lines, so no document needs a detail call. A contact
 * names its country by an id of the account's code list, so the countries are
 * copied too, and a document its currency, payment option and constant symbol,
 * so those code lists are as well.
 */
enum IdokladResource: string
{
    case Contacts = 'Contacts';
    case NumericSequences = 'NumericSequences';
    case IssuedInvoices = 'IssuedInvoices';
    case ProformaInvoices = 'ProformaInvoices';
    case IssuedTaxDocuments = 'IssuedTaxDocuments';
    case CreditNotes = 'CreditNotes';
    case RecurringInvoices = 'RecurringInvoices';
    case IssuedDocumentPayments = 'IssuedDocumentPayments';
    case Logs = 'Logs';
    case Countries = 'Countries';
    case Currencies = 'Currencies';
    case PaymentOptions = 'PaymentOptions';
    case ConstantSymbols = 'ConstantSymbols';

    public function path(): string
    {
        return '/'.$this->value;
    }

    /**
     * The column a list filters on to return only what changed after a moment;
     * null for a list without one, which is pulled whole every time.
     */
    public function changeFilter(): ?string
    {
        return match ($this) {
            self::NumericSequences, self::IssuedDocumentPayments, self::Countries, self::Currencies, self::PaymentOptions, self::ConstantSymbols => null,
            self::Logs => 'DateFrom',
            default => 'DateLastChange',
        };
    }

    /**
     * The report type of the document's PDF; null when the resource has none.
     */
    public function pdfReport(): ?string
    {
        return match ($this) {
            self::IssuedInvoices => 'IssuedInvoice',
            self::ProformaInvoices => 'ProformaInvoice',
            self::IssuedTaxDocuments => 'IssuedTaxDocument',
            self::CreditNotes => 'CreditNote',
            default => null,
        };
    }
}
