<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application\Api;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * Brings an invoice back from the trash over the API and MCP.
 */
#[Operation(
    name: 'invoices.restore',
    method: 'POST',
    path: '/api/v1/invoices/{invoiceId}/restore',
    tool: 'foresi_restore_invoice',
    scope: 'invoices:write',
    title: 'Restore an invoice',
    description: 'Brings a sales document back from the trash with its number and content; one not in the trash is answered as it is. '
        .'A tax document of a cancelled payment stays in the trash (cancelled), and one whose advance or billed work was taken by another document meanwhile is refused with already_deducted.',
    response: 'InvoiceDetail',
)]
final readonly class RestoreInvoiceInput
{
    public function __construct(
        #[Field('The id of the invoice.', format: 'uuid')]
        public string $invoiceId,
    ) {
    }
}
