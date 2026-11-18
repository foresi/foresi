<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Mcp;

use Foresi\Purchasing\Application\PurchasingNotAllowed;
use Foresi\Purchasing\Application\Query\ReceivedDocumentBalances;
use Foresi\Purchasing\Infrastructure\Api\ReceivedDocumentJson;
use Foresi\Purchasing\Infrastructure\Api\ReceivedDocumentListQuery;
use Foresi\Shared\Infrastructure\Mcp\ReadTool;
use Foresi\Shared\Infrastructure\Mcp\ToolArguments;
use Foresi\Shared\Infrastructure\Mcp\ToolOutput;
use Foresi\Shared\Infrastructure\Mcp\ToolRefusal;

/**
 * The received documents of the organisation, narrowed and paged as GET
 * /api/v1/received-documents does it, each as the API writes it.
 */
final readonly class SearchReceivedDocumentsTool implements ReadTool
{
    public function __construct(private ReceivedDocumentBalances $balances, private ToolArguments $arguments)
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
            .'Returns {items, next_cursor}: each document has its id, firm, state, kind, treatment, evidence number, supplier, supplier number, variable symbol, dates, total, base, VAT, what is paid and what remains. '
            .'Money is {amount_minor, currency}, in hundredths of the currency; a credit note has a negative total, while paid and remaining are positive. '
            .'When next_cursor is not null, more documents match: call again with the same arguments and next_cursor as cursor. '
            .'Use foresi_list_firms for a firm_id and foresi_search_contacts for a supplier_id.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'firm_id' => ToolArguments::idSchema('Only documents of this firm of the organisation.'),
                'supplier_id' => ToolArguments::idSchema('Only documents of this supplier, a contact.'),
                'state' => ToolArguments::choiceSchema(['draft', 'recorded'], 'Only drafts waiting to be completed, or only recorded documents.'),
                'unpaid' => ['type' => 'boolean', 'default' => false, 'description' => 'True returns only recorded documents with something left to pay.'],
                'due_before' => ToolArguments::dateSchema('Only documents due before this day, not on it.'),
                'trashed' => ['type' => 'boolean', 'default' => false, 'description' => 'True returns only the documents in the trash.'],
            ] + ToolArguments::pagingSchema(),
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $query = new ReceivedDocumentListQuery(
            ToolArguments::text($arguments, 'firm_id'),
            ToolArguments::text($arguments, 'supplier_id'),
            ToolArguments::text($arguments, 'state'),
            ToolArguments::flag($arguments, 'unpaid'),
            ToolArguments::text($arguments, 'due_before'),
            ToolArguments::flag($arguments, 'trashed'),
            ToolArguments::limit($arguments),
            ToolArguments::text($arguments, 'cursor'),
        );
        $this->arguments->validate($query);
        $afterId = ToolArguments::afterId($query->cursor);
        try {
            $slice = $this->balances->slice($query->filter(), $afterId, $query->limit);
        } catch (PurchasingNotAllowed) {
            throw ToolRefusal::forbidden('Only the owners and members of the organisation read its received documents.');
        }

        return ToolArguments::page(array_map(ReceivedDocumentJson::row(...), $slice->items), $slice->hasMore ? $slice->lastId() : null, 'received document', 'received documents');
    }
}
