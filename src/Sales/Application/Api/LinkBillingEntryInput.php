<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application\Api;

use Foresi\Sales\Contract\BillingDocumentType;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A planned invoice of a won deal linked to the invoice or recurring invoice
 * that fulfils it, over the API and MCP (ADR 0032).
 */
#[Operation(
    name: 'sales.billing_entries.link',
    method: 'PUT',
    path: '/api/v1/sales/deals/{dealId}/billing-plan/{entryId}/fulfilment',
    tool: 'foresi_link_billing_entry',
    scope: 'sales:write',
    title: 'Link a planned invoice to its invoice',
    description: 'Links a planned invoice to the invoice (a one_off entry) or recurring invoice (a recurring entry) of the same client that fulfils it, such as one issued before the plan existed; the forecast then counts that document and no longer the entry, so nothing is counted twice. The invoice is not in the trash, the recurring invoice neither ended nor in the trash, and a document fulfils one entry. Find them with foresi_search_invoices and foresi_list_recurring_invoices.',
    response: 'SalesDeal',
    staffOnly: true,
)]
final readonly class LinkBillingEntryInput
{
    public function __construct(
        #[Field('The id of the deal.', format: 'uuid')]
        public string $dealId,
        #[Field('The id of the planned invoice.', format: 'uuid')]
        public string $entryId,
        #[Field('invoice for an issued invoice, recurring_invoice for a recurring invoice.', enum: [BillingDocumentType::Invoice->value, BillingDocumentType::RecurringInvoice->value])]
        public string $type,
        #[Field('The id of the invoice or recurring invoice.', format: 'uuid')]
        public string $documentId,
    ) {
    }
}
