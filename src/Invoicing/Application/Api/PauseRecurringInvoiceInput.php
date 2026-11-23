<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application\Api;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * Pause a recurring invoice over the API and MCP, as the row menu of the list does (RecurringLifecycle).
 */
#[Operation(
    name: 'recurring-invoices.pause',
    method: 'POST',
    path: '/api/v1/recurring-invoices/{recurringInvoiceId}/pause',
    tool: 'foresi_pause_recurring_invoice',
    scope: 'invoices:write',
    title: 'Pause a recurring invoice',
    description: 'Pauses a recurring invoice: it issues nothing until it is resumed, and the dates that pass meanwhile are skipped, not issued later. Repeatable. An ended one cannot pause.',
    destructive: false,
    response: 'RecurringInvoiceDetail',
)]
final readonly class PauseRecurringInvoiceInput
{
    public function __construct(
        #[Field('The id of the recurring invoice.', format: 'uuid')]
        public string $recurringInvoiceId,
    ) {
    }
}
