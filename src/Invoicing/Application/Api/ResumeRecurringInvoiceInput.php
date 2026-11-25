<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * Resume a recurring invoice over the API and MCP, as the row menu of the list does (RecurringLifecycle).
 */
#[Operation(
    name: 'recurring-invoices.resume',
    method: 'POST',
    path: '/api/v1/recurring-invoices/{recurringInvoiceId}/resume',
    tool: 'foresi_resume_recurring_invoice',
    scope: 'invoices:write',
    title: 'Resume a recurring invoice',
    description: 'Resumes a paused recurring invoice from the first date of its schedule on or after today; the dates that fell in the pause are not issued, and the summary names them. A date due today may be issued at once. An ended one cannot resume.',
    destructive: false,
    response: 'RecurringInvoiceDetail',
)]
final readonly class ResumeRecurringInvoiceInput
{
    public function __construct(
        #[Field('The id of the recurring invoice.', format: 'uuid')]
        public string $recurringInvoiceId,
    ) {
    }
}
