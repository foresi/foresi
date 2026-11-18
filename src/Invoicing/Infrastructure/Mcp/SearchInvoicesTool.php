<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Mcp;

use Foresi\Invoicing\Application\InvoicingNotAllowed;
use Foresi\Invoicing\Application\Query\InvoiceList;
use Foresi\Invoicing\Application\Query\InvoiceSliceFilter;
use Foresi\Invoicing\Domain\DocumentKind;
use Foresi\Invoicing\Infrastructure\Api\InvoiceJson;
use Foresi\Invoicing\Infrastructure\Api\InvoiceListQuery;
use Foresi\Invoicing\Infrastructure\Api\InvoiceViewFilter;
use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Application\View\SavedViewRefused;
use Foresi\Shared\Application\View\SavedViews;
use Foresi\Shared\Infrastructure\Mcp\ReadTool;
use Foresi\Shared\Infrastructure\Mcp\ToolArguments;
use Foresi\Shared\Infrastructure\Mcp\ToolOutput;
use Foresi\Shared\Infrastructure\Mcp\ToolRefusal;
use Foresi\Shared\Infrastructure\View\Api\ViewJson;

/**
 * The sales documents of the organisation, narrowed and paged as GET
 * /api/v1/invoices does it, each item as the API writes it, through a saved view or a
 * filter when asked (spec D13).
 */
final readonly class SearchInvoicesTool implements ReadTool
{
    public function __construct(private InvoiceList $list, private ToolArguments $arguments, private SavedViews $views, private ScopeGuard $scope, private ViewJson $json)
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

    public function scope(): string
    {
        return 'invoices:read';
    }

    public function description(): string
    {
        return 'Searches the sales documents of the organisation (invoices, advance invoices, payment tax documents and correction documents), newest first. '
            .'Every argument narrows the search, all joined by AND; with none, it returns the newest documents. '
            .'view_id lists what a saved view holds (find views with foresi_list_views; it needs the views:read scope) and filter adds conditions in the format of foresi_list_lists, such as {"conditions": [{"field": "due_date", "op": "is", "value": {"relative": "next_n_days", "n": 7}}]}. '
            .'Returns {items, next_cursor, applied_view}: each item has the id, number, kind, firm, customer, issue, due and tax point dates, the total, paid and remaining amounts, the payment state and whether it is overdue, delivered or in the trash. '
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
                'view_id' => ['type' => 'string', 'description' => 'Only the documents a saved view holds: its id as foresi_list_views gives it, or the system key of a view a module ships, such as "invoicing.invoices.overdue". Without kind, the kind of the view\'s list.'],
                'filter' => ['type' => 'object', 'description' => 'Conditions all documents meet, {"conditions": [{"field", "op", "value"}]}, checked against the list of kind (of the invoices without one); its fields and operators are in foresi_list_lists.'],
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
        $viewId = ToolArguments::text($arguments, 'view_id');
        if (null !== $viewId && !$this->scope->allows(InvoiceViewFilter::VIEWS_SCOPE)) {
            throw new ToolRefusal(ToolRefusal::INSUFFICIENT_SCOPE, \sprintf('The token does not have the "%s" scope a saved view needs.', InvoiceViewFilter::VIEWS_SCOPE));
        }
        try {
            $applied = InvoiceViewFilter::of($this->views, $this->scope, $query->kind, $viewId, $arguments['filter'] ?? null);
            $slice = $this->list->slice($query->filter($applied->filter, $applied->kind), $afterId, $query->limit);
        } catch (SavedViewRefused $refused) {
            throw ToolRefusal::of($refused);
        } catch (InvoicingNotAllowed) {
            throw ToolRefusal::forbidden('Only the owners and members of the organisation read its invoices.');
        }
        $page = ToolArguments::page(array_map(InvoiceJson::item(...), $slice->items), $slice->nextAfterId(), 'document', 'documents');
        if (null === $applied->view) {
            return new ToolOutput($page->data + ['applied_view' => null], $page->summary);
        }
        $view = $this->json->view($applied->view);

        return new ToolOutput($page->data + ['applied_view' => $view], \sprintf('Through the view %s: %s', \is_string($view['name']) ? $view['name'] : '', $page->summary));
    }
}
