<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application\Api;

use Foresi\Invoicing\Domain\Invoice;
use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * Moves an invoice to the trash over the API and MCP, as the detail screen does.
 */
#[Operation(
    name: 'invoices.trash',
    method: 'POST',
    path: '/api/v1/invoices/{invoiceId}/trash',
    tool: 'foresi_trash_invoice',
    scope: 'invoices:write',
    title: 'Trash an invoice',
    description: 'Moves a sales document to the trash with a reason, such as one issued by mistake: it keeps its number and content, leaves the lists and the VAT reports, its queued sends stop and its links end. '
        .'A delivered document cannot go to the trash (not_trashable): correct it with a correction document in Foresi instead; one with payments needs them removed first (has_payments). Ask the person before you call it. Bring it back with foresi_restore_invoice.',
    destructive: true,
    response: 'InvoiceDetail',
)]
final readonly class TrashInvoiceInput
{
    public function __construct(
        #[Field('The id of the invoice.', format: 'uuid')]
        public string $invoiceId,
        #[Field('Why it goes to the trash, kept in its history.', maxLength: Invoice::MAX_REASON_LENGTH)]
        public string $reason,
    ) {
    }
}
