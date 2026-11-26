<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Infrastructure\Mcp;

use Foresi\Margin\Application\AllocationReads;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Margin\Infrastructure\Api\MarginJson;
use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Contract\Mcp\ToolSchema;

/**
 * The margin of one received document, as GET /api/v1/margin/documents/{documentId} writes it.
 */
final readonly class DocumentAllocationTool implements ReadTool
{
    public function __construct(private AllocationReads $reads)
    {
    }

    public function name(): string
    {
        return 'foresi_get_document_allocation';
    }

    public function title(): string
    {
        return 'Get the allocation of a document';
    }

    public function scope(): string
    {
        return 'margin:read';
    }

    public function description(): string
    {
        return 'Returns who bears the cost of a recorded received document: each effective line with its key, text, base_czk (null while a foreign document has no exchange rate), its own allocation (kind and recipients, or null), and its shares, each with recipient ("own", a contact id, or null while unassigned), recipient_name, amount in CZK and its source (line, document, planned_item, supplier or unassigned). '
            .'document_allocation is the assignment of the whole document, if any; known_gaps lists what cannot be counted yet, such as missing_exchange_rate. Use the line keys with foresi_set_line_allocation. Find the document id with foresi_search_received_documents.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['document_id' => ToolSchema::idSchema('The id of the received document, as foresi_search_received_documents returns it.')],
            'required' => ['document_id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $view = $this->reads->document(ToolSchema::text($arguments, 'document_id') ?? '');
        } catch (MarginRefused $refused) {
            throw ToolRefusal::of($refused);
        }

        return new ToolOutput(MarginJson::document($view), \sprintf('The document with %d lines and their shares.', \count($view->lines)));
    }
}
