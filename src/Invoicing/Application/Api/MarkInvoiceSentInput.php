<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application\Api;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * Marks a sales document sent by hand over the API and MCP, as the row menu of the list does.
 */
#[Operation(
    name: 'invoices.mark_sent',
    method: 'POST',
    path: '/api/v1/invoices/{invoiceId}/mark-sent',
    tool: 'foresi_mark_invoice_sent',
    scope: 'invoices:write',
    title: 'Mark an invoice as sent',
    description: 'Records that a sales document went to the customer outside Foresi, such as handed over or e-mailed by hand; an e-mail sent from Foresi marks it by itself. '
        .'It cannot be undone: a sent document can no longer go to the trash and its series and number are fixed, so correct it with a correction document instead. Ask the person before you call it. '
        .'One sent already is answered as it is, with its first sent_at; one in the trash is refused with trashed, so restore it first with foresi_restore_invoice.',
    destructive: true,
    response: 'InvoiceDetail',
)]
final readonly class MarkInvoiceSentInput
{
    public function __construct(
        #[Field('The id of the invoice.', format: 'uuid')]
        public string $invoiceId,
    ) {
    }
}
