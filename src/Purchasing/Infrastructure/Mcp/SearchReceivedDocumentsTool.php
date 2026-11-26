<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Mcp;

use Foresi\Party\Application\InstanceFirm;
use Foresi\Purchasing\Application\PurchasingNotAllowed;
use Foresi\Purchasing\Application\Query\ReceivedDocumentBalances;
use Foresi\Purchasing\Application\View\ReceivedDocumentLists;
use Foresi\Purchasing\Infrastructure\Api\ReceivedDocumentJson;
use Foresi\Purchasing\Infrastructure\Api\ReceivedDocumentListQuery;
use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Application\View\AppliedView;
use Foresi\Shared\Application\View\SavedViewRefused;
use Foresi\Shared\Application\View\SavedViews;
use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Contract\Mcp\ToolSchema;
use Foresi\Shared\Domain\View\SavedView;
use Foresi\Shared\Infrastructure\Mcp\ToolArguments;
use Foresi\Shared\Infrastructure\View\Api\ViewJson;

/**
 * The received documents of the organisation, narrowed and paged as GET
 * /api/v1/received-documents does it, each as the API writes it.
 */
final readonly class SearchReceivedDocumentsTool implements ReadTool
{
    public function __construct(private ReceivedDocumentLists $receivedLists, private ReceivedDocumentBalances $balances, private ToolArguments $arguments, private SavedViews $views, private ScopeGuard $scope, private ViewJson $json, private InstanceFirm $firm)
    {
    }

    public function name(): string
    {
        return 'foresi_search_received_documents';
    }

    public function title(): string
    {
        return 'Search received documents';
    }

    public function scope(): string
    {
        return 'received_documents:read';
    }

    public function description(): string
    {
        return 'Searches the documents the organisation\'s firms received from suppliers (invoices, receipts, credit notes, advance requests), newest first, drafts and recorded ones, outside the trash unless trashed is true. '
            .'unpaid: true returns the recorded documents with something left to pay, due_before the ones due before a day; together they are the payables of a period. '
            .'view_id lists what a saved view holds (find views with foresi_list_views; it needs the views:read scope) and filter adds conditions in the format of foresi_list_lists, such as {"conditions": [{"field": "kind", "op": "is", "value": "credit_note"}]}; a condition on trashed decides it instead of the trashed argument. '
            .'Returns {items, next_cursor, applied_view}: each document has its id, firm, state, kind, treatment, evidence number, supplier, supplier number, variable symbol, dates, total, base, VAT, what is paid and what remains. '
            .'Money is {amount_minor, currency}, in hundredths of the currency; a credit note has a negative total, while paid and remaining are positive. '
            .'When next_cursor is not null, more documents match: call again with the same arguments and next_cursor as cursor. '
            .'reading narrows to the documents whose file has that state of reading (a recorded document keeps its reading too), source to how a document came in. '
            .'Use foresi_search_contacts for a supplier_id.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'supplier_id' => ToolSchema::idSchema('Only documents of this supplier, a contact.'),
                'state' => ToolSchema::choiceSchema(['draft', 'recorded'], 'Only drafts waiting to be completed, or only recorded documents.'),
                'unpaid' => ['type' => 'boolean', 'default' => false, 'description' => 'True returns only recorded documents with something left to pay.'],
                'due_before' => ToolSchema::dateSchema('Only documents due before this day, not on it.'),
                'trashed' => ['type' => 'boolean', 'default' => false, 'description' => 'True returns only the documents in the trash.'],
                'reading' => ToolSchema::choiceSchema(['reading', 'read', 'nothing_found', 'failed'], 'Only documents whose file is being read, was read with values found, was read with nothing found, or could not be read.'),
                'source' => ToolSchema::choiceSchema(['manual', 'upload', 'api', 'mail'], 'Only documents typed in by a person, made from a file uploaded in Foresi, made over the API and MCP, or filed from a mail.'),
                'view_id' => ['type' => 'string', 'description' => 'Only the documents a saved view of the list purchasing.received holds: its id as foresi_list_views gives it, or the system key of a view a module ships, such as "purchasing.received.drafts" or "purchasing.received.to_review" (drafts whose file was read, found nothing or failed).'],
                'filter' => ['type' => 'object', 'description' => 'Conditions all documents meet, {"conditions": [{"field", "op", "value"}]}, checked against the list purchasing.received; its fields and operators are in foresi_list_lists.'],
            ] + ToolSchema::pagingSchema(),
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $query = new ReceivedDocumentListQuery(
            ToolSchema::text($arguments, 'supplier_id'),
            ToolSchema::text($arguments, 'state'),
            ToolSchema::flag($arguments, 'unpaid'),
            ToolSchema::text($arguments, 'due_before'),
            ToolSchema::flag($arguments, 'trashed'),
            ToolSchema::limit($arguments),
            ToolSchema::text($arguments, 'cursor'),
            reading: ToolSchema::text($arguments, 'reading'),
            source: ToolSchema::text($arguments, 'source'),
        );
        $this->arguments->validate($query);
        $afterId = ToolArguments::afterId($query->cursor);
        $viewId = ToolSchema::text($arguments, 'view_id');
        if (null !== $viewId && !$this->scope->allows(AppliedView::VIEWS_SCOPE)) {
            throw new ToolRefusal(ToolRefusal::INSUFFICIENT_SCOPE, \sprintf('The token does not have the "%s" scope a saved view needs.', AppliedView::VIEWS_SCOPE));
        }
        try {
            $applied = AppliedView::of($this->views, $this->scope, $this->receivedLists->list(), $viewId, $arguments['filter'] ?? null);
            $slice = $this->balances->slice($query->filter($applied->filter), $afterId, $query->limit);
        } catch (SavedViewRefused $refused) {
            throw ToolRefusal::of($refused);
        } catch (PurchasingNotAllowed) {
            throw ToolRefusal::forbidden('Only the owners and members of the organisation read its received documents.');
        }
        $page = ToolArguments::page(ReceivedDocumentJson::rows($slice->items, $this->firm), $slice->hasMore ? $slice->lastId() : null, 'received document', 'received documents');
        if (!$applied->view instanceof SavedView) {
            return new ToolOutput($page->data + ['applied_view' => null], $page->summary);
        }
        $view = $this->json->view($applied->view);

        return new ToolOutput($page->data + ['applied_view' => $view], \sprintf('Through the view %s: %s', \is_string($view['name']) ? $view['name'] : '', $page->summary));
    }
}
