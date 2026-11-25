<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Mcp;

use Foresi\Invoicing\Application\Api\InvoicingRefused;
use Foresi\Invoicing\Application\Api\RecurringWrites;
use Foresi\Invoicing\Domain\RecurringState;
use Foresi\Invoicing\Infrastructure\Api\RecurringInvoiceJson;
use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Contract\Mcp\ToolSchema;

/**
 * foresi_list_recurring_invoices: the recurring templates as the list shows them.
 */
final readonly class ListRecurringInvoicesTool implements ReadTool
{
    public function __construct(private RecurringWrites $writes)
    {
    }

    public function name(): string
    {
        return 'foresi_list_recurring_invoices';
    }

    public function title(): string
    {
        return 'List recurring invoices';
    }

    public function scope(): string
    {
        return 'invoices:read';
    }

    public function description(): string
    {
        return 'Returns the recurring invoices of the firm out of the trash, the soonest next issue first and ended ones last, 25 a page: '
            .'{items} with the id, name, state (active, paused or ended), customer, description, schedule, delivery (send e-mails each invoice, review only issues it), next_issue_on, last_issue_on, issued_count and failing, and page, pages and total. '
            .'Read one with its lines and next issues with foresi_get_recurring_invoice.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'state' => ToolSchema::choiceSchema(['active', 'paused', 'ended'], 'Only the recurring invoices in this state.'),
                'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The page, from 1.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $state = ToolSchema::text($arguments, 'state');
        $page = $arguments['page'] ?? 1;
        if (!\is_int($page) || $page < 1) {
            throw ToolRefusal::invalidArgument('page must be a whole number from 1.');
        }
        try {
            $found = $this->writes->page(null === $state ? null : (RecurringState::tryFrom($state) ?? throw ToolRefusal::invalidArgument('state is active, paused or ended.')), $page);
        } catch (InvoicingRefused $refused) {
            throw ToolRefusal::of($refused);
        }

        return new ToolOutput(
            ['items' => array_map(RecurringInvoiceJson::item(...), $found->templates), 'page' => $found->page, 'pages' => $found->pages, 'total' => $found->total],
            1 === $found->total ? '1 recurring invoice.' : \sprintf('%d recurring invoices, page %d of %d.', $found->total, $found->page, max(1, $found->pages)),
        );
    }
}
