<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Domain;

/**
 * What a received document is. A tax invoice and a simplified receipt are
 * domestic tax documents, a foreign invoice comes from a supplier abroad and is
 * usually self-assessed, a credit note corrects a document received earlier and
 * names it. An advance request asks for payment before the supply and an other
 * document (a contract, a fee) carries no VAT; neither needs a VAT recap.
 */
enum ReceivedDocumentKind: string
{
    case TaxInvoice = 'tax_invoice';
    case SimplifiedReceipt = 'simplified_receipt';
    case ForeignInvoice = 'foreign_invoice';
    case CreditNote = 'credit_note';
    case AdvanceRequest = 'advance_request';
    case Other = 'other';

    /**
     * Whether a recorded document of this kind needs at least one row of the VAT recap.
     */
    public function needsVatRecap(): bool
    {
        return self::AdvanceRequest !== $this && self::Other !== $this;
    }

    /**
     * Whether the document can carry VAT at all; the other kinds are never VAT documents.
     */
    public function carriesVat(): bool
    {
        return $this->needsVatRecap();
    }
}
