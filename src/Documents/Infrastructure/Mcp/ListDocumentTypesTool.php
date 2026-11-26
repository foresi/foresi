<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Mcp;

use Foresi\Documents\Application\DocumentReads;
use Foresi\Documents\Application\DocumentsRefused;
use Foresi\Documents\Infrastructure\Api\DocumentsJson;
use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\StaffOnlyTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Contract\Mcp\ToolSchema;

/**
 * The document types, as GET /api/v1/document-types writes them.
 */
final readonly class ListDocumentTypesTool implements ReadTool, StaffOnlyTool
{
    public function __construct(private DocumentReads $reads, private DocumentsJson $json)
    {
    }

    public function name(): string
    {
        return 'foresi_list_document_types';
    }

    public function title(): string
    {
        return 'List document types';
    }

    public function scope(): string
    {
        return 'documents:read';
    }

    public function description(): string
    {
        return 'Returns the document types by name: {items} with the id, name, code, whether documents of the type are signed and the signature level. '
            .'There are few, so there is no paging; archived types only with include_archived. Use an id with foresi_list_document_templates or foresi_create_document.';
    }

    public function staffOnly(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['include_archived' => ['type' => 'boolean', 'default' => false, 'description' => 'Whether archived types are listed too.']],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $types = $this->reads->types(ToolSchema::flag($arguments, 'include_archived'));
        } catch (DocumentsRefused $refused) {
            throw ToolRefusal::of($refused);
        }
        $count = \count($types);

        return new ToolOutput(
            ['items' => array_map($this->json->type(...), $types)],
            1 === $count ? '1 document type.' : \sprintf('%d document types.', $count),
        );
    }
}
