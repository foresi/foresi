<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Mcp;

use App\Invoicing\Application\InvoicingNotAllowed;
use App\Invoicing\Application\Query\InvoiceList;
use App\Invoicing\Application\Query\InvoiceSliceFilter;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Infrastructure\Api\InvoiceJson;
use App\Invoicing\Infrastructure\Api\InvoiceListQuery;
use App\Shared\Infrastructure\Mcp\ReadTool;
use App\Shared\Infrastructure\Mcp\ToolArguments;
use App\Shared\Infrastructure\Mcp\ToolOutput;
use App\Shared\Infrastructure\Mcp\ToolRefusal;

/**
 * The sales documents of the organisation, narrowed and paged as GET
 * /api/v1/invoices does it, each item as the API writes it.
 */
final readonly class SearchInvoicesTool implements ReadTool
{
    public function __construct(private InvoiceList $list, private ToolArguments $arguments)
    {
    }

    public function name(): string
    {
        return 'foresi_search_invoices';
    }

    public function title(): string
    {
        return 'Search invoices';
    }

    public function description(): string
    {
        return 'Searches the sales documents of the organisation (invoices, advance invoices, payment tax documents and correction documents), newest first. '
            .'Every argument narrows the search; with none, it returns the newest documents. '
            .'Returns {items, next_cursor}: each item has the id, number, kind, firm, customer, issue, due and tax point dates, the total, paid and remaining amounts, the payment state and whether it is overdue, delivered or in the trash. '
            .'Money is {amount_minor, currency}, in hundredths of the currency. '
            .'When next_cursor is not null, more documents match: call again with the same arguments and next_cursor as cursor. '
            .'Use foresi_get_invoice for the lines, payments and balances of one document, foresi_list_firms for a firm_id and foresi_search_contacts for a customer_id.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kind' => ToolArguments::choiceSchema(array_column(DocumentKind::cases(), 'value'), 'Only documents of this kind.'),
                'firm_id' => ToolArguments::idSchema('Only documents issued by this firm of the organisation.'),
                'customer_id' => ToolArguments::idSchema('Only documents issued to this contact.'),
                'state' => ToolArguments::choiceSchema(InvoiceSliceFilter::STATES, 'Only documents in this payment state: unpaid (overdue ones too), overdue (unpaid after the due date) or paid. Ignored with trashed.'),
                'trashed' => ['type' => 'boolean', 'default' => false, 'description' => 'True returns only the documents in the trash, false only those that are not.'],
                'issued_from' => ToolArguments::dateSchema('Only documents issued on this day or later.'),
                'issued_to' => ToolArguments::dateSchema('Only documents issued on this day or earlier.'),
                'query' => ['type' => 'string', 'description' => 'Text looked for in the number, the customer name, the variable symbol and the description.'],
            ] + ToolArguments::pagingSchema(),
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $query = new InvoiceListQuery(
            ToolArguments::text($arguments, 'kind'),
            ToolArguments::text($arguments, 'firm_id'),
            ToolArguments::text($arguments, 'customer_id'),
            ToolArguments::text($arguments, 'state'),
            ToolArguments::flag($arguments, 'trashed'),
            ToolArguments::text($arguments, 'issued_from'),
            ToolArguments::text($arguments, 'issued_to'),
            ToolArguments::text($arguments, 'query') ?? '',
            ToolArguments::limit($arguments),
            ToolArguments::text($arguments, 'cursor'),
        );
        $this->arguments->validate($query);
        $afterId = ToolArguments::afterId($query->cursor);
        try {
            $slice = $this->list->slice($query->filter(), $afterId, $query->limit);
        } catch (InvoicingNotAllowed) {
            throw ToolRefusal::forbidden('Only the owners and members of the organisation read its invoices.');
        }

        return ToolArguments::page(array_map(InvoiceJson::item(...), $slice->items), $slice->nextAfterId(), 'document', 'documents');
    }
}
