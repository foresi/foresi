<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * End a recurring invoice over the API and MCP, as the row menu of the list does (RecurringLifecycle).
 */
#[Operation(
    name: 'recurring-invoices.end',
    method: 'POST',
    path: '/api/v1/recurring-invoices/{recurringInvoiceId}/end',
    tool: 'foresi_end_recurring_invoice',
    scope: 'invoices:write',
    title: 'End a recurring invoice',
    description: 'Ends a recurring invoice for good: it issues nothing more and cannot be resumed or changed; the invoices it issued stay. Repeatable. Ask the person before you call it; to stop it for a while, pause it with foresi_pause_recurring_invoice instead.',
    destructive: true,
    response: 'RecurringInvoiceDetail',
)]
final readonly class EndRecurringInvoiceInput
{
    public function __construct(
        #[Field('The id of the recurring invoice.', format: 'uuid')]
        public string $recurringInvoiceId,
    ) {
    }
}
