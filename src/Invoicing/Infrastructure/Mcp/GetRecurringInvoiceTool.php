<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Mcp;

use Foresi\Invoicing\Application\Api\InvoicingRefused;
use Foresi\Invoicing\Application\Api\RecurringWrites;
use Foresi\Invoicing\Infrastructure\Api\RecurringInvoiceJson;
use Foresi\Shared\Infrastructure\Mcp\ReadTool;
use Foresi\Shared\Infrastructure\Mcp\ToolArguments;
use Foresi\Shared\Infrastructure\Mcp\ToolOutput;
use Foresi\Shared\Infrastructure\Mcp\ToolRefusal;

/**
 * foresi_get_recurring_invoice: one template with its lines and next issues.
 */
final readonly class GetRecurringInvoiceTool implements ReadTool
{
    public function __construct(private RecurringWrites $writes, private RecurringInvoiceJson $json)
    {
    }

    public function name(): string
    {
        return 'foresi_get_recurring_invoice';
    }

    public function title(): string
    {
        return 'Get a recurring invoice';
    }

    public function scope(): string
    {
        return 'invoices:read';
    }

    public function description(): string
    {
        return 'Returns one recurring invoice by its id, in the trash too: its version (send it to foresi_update_recurring_invoice), name, state, customer, series, payment, texts, '
            .'schedule {first_issue_on, repeat, every, last_day_of_month, end, ends_on, ends_after}, tax_point, due_days, delivery (send e-mails each invoice to the customer; review only issues it, for the person to send), the copies, '
            .'next_issue_on, last_issue_on, issued_count, last_failure, its lines in the shape foresi_create_invoice takes them, and next_issues: the next invoices it issues, each with its issue, tax point and due dates and description (none while paused or ended). '
            .'Find the id with foresi_list_recurring_invoices.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ToolArguments::idSchema('The id of the recurring invoice, as foresi_list_recurring_invoices returns it.')],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $template = $this->writes->template(ToolArguments::text($arguments, 'id') ?? '');
        } catch (InvoicingRefused $refused) {
            throw ToolRefusal::of($refused);
        }

        return new ToolOutput($this->json->detail($template), 'The recurring invoice with its schedule, delivery, lines and next issues.');
    }
}
