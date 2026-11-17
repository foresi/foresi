<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application\Api;

use App\Purchasing\Domain\ReceivedDocument;
use App\Shared\Application\Api\Field;
use App\Shared\Application\Api\Operation;

/**
 * A received document typed in over the API and MCP and recorded at once
 * (decision C4 of the cash flow spec), with no file: the original can be
 * uploaded to the document in Foresi later. It takes the next evidence number
 * of the firm, as recording on the screen does.
 */
#[Operation(
    name: 'received_documents.record',
    method: 'POST',
    path: '/api/v1/received-documents',
    tool: 'foresi_record_received_document',
    scope: 'received_documents:write',
    title: 'Record a received document',
    description: 'Records a document the firm received from a supplier (an invoice, a receipt, a credit note, an advance request) straight from its values, with no file; it gets the next evidence number of the firm and counts in the VAT reports. '
        .'The supplier must be a contact: find it with foresi_search_contacts (by registration_number or name), or create it with foresi_create_contact. The firm comes from foresi_list_firms. '
        .'Amounts are decimals in the document currency, such as "1210.00"; a credit note has a negative total. The VAT recap is one line per rate, "rate:base:vat", such as "21:1000.00:210.00"; a document in another currency may add the VAT in CZK it prints, "21:100.00:21.00:510.30". '
        .'Before recording, search the firm\'s documents for the supplier number to avoid recording one twice. Record its payments with foresi_add_received_document_payment or foresi_mark_received_document_paid.',
    status: 201,
    response: 'ReceivedDocumentDetail',
)]
final readonly class RecordReceivedDocumentInput
{
    /**
     * @param list<string> $vatRecap
     */
    public function __construct(
        #[Field('The id of the firm that received the document.', format: 'uuid')]
        public string $firmId,
        #[Field('What the document is.', enum: ['tax_invoice', 'simplified_receipt', 'foreign_invoice', 'credit_note', 'advance_request', 'other'])]
        public string $kind,
        #[Field('The id of the supplier, a contact of the address book.', format: 'uuid')]
        public string $supplierId,
        #[Field('The day the supplier issued it, YYYY-MM-DD.', format: 'date')]
        public string $issueDate,
        #[Field('What the document asks to be paid, in its currency, such as "1210.00"; negative for a credit note.')]
        public string $total,
        #[Field('How its VAT is treated: domestic (VAT charged by a Czech supplier), domestic_reverse_charge, eu_service, non_eu_service, non_deductible, or not_vat_document for a document with no VAT (a receipt of a non-payer, an advance request, a foreign invoice of goods).', enum: ['domestic', 'domestic_reverse_charge', 'eu_service', 'non_eu_service', 'not_vat_document', 'non_deductible'])]
        public string $treatment = 'domestic',
        #[Field('The number the supplier gave the document.', maxLength: ReceivedDocument::MAX_SUPPLIER_NUMBER_LENGTH)]
        public string $supplierNumber = '',
        #[Field('The variable symbol to pay with, up to 10 digits; empty for none.', maxLength: 10)]
        public string $variableSymbol = '',
        #[Field('The tax point, YYYY-MM-DD; the issue date when left out.', format: 'date')]
        public ?string $taxPointDate = null,
        #[Field('The day it is due, YYYY-MM-DD; null for none.', format: 'date')]
        public ?string $dueDate = null,
        #[Field('The day the firm received it, YYYY-MM-DD; today when left out. It decides the period the VAT is deducted in.', format: 'date')]
        public ?string $receivedDate = null,
        #[Field('The currency, ISO 4217, such as CZK or EUR.', maxLength: 3)]
        public string $currency = 'CZK',
        #[Field('The VAT recap, one line per rate: "rate:base:vat" or "rate:base:vat:stated_vat_czk", such as "21:1000.00:210.00". Needed for a tax invoice and a simplified receipt.', items: 'string')]
        public array $vatRecap = [],
        #[Field('CZK for one unit of a foreign currency, such as "24.310"; the published rate of the tax point when left out.')]
        public ?string $exchangeRate = null,
        #[Field('The id of the recorded received document a credit note corrects.', format: 'uuid')]
        public ?string $originalDocumentId = null,
        #[Field('The code of the supply of a domestic reverse charge, for the control statement; optional.', maxLength: ReceivedDocument::MAX_SUPPLY_CODE_LENGTH)]
        public ?string $supplyCode = null,
    ) {
    }
}
