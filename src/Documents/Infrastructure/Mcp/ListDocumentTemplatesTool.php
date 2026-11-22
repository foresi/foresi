<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Mcp;

use Foresi\Documents\Application\DocumentReads;
use Foresi\Documents\Application\DocumentsRefused;
use Foresi\Documents\Infrastructure\Api\DocumentsJson;
use Foresi\Shared\Infrastructure\Mcp\ReadTool;
use Foresi\Shared\Infrastructure\Mcp\StaffOnlyTool;
use Foresi\Shared\Infrastructure\Mcp\ToolArguments;
use Foresi\Shared\Infrastructure\Mcp\ToolOutput;
use Foresi\Shared\Infrastructure\Mcp\ToolRefusal;

/**
 * The document templates, as GET /api/v1/document-templates writes them.
 */
final readonly class ListDocumentTemplatesTool implements ReadTool, StaffOnlyTool
{
    public function __construct(private DocumentReads $reads, private DocumentsJson $json)
    {
    }

    public function name(): string
    {
        return 'foresi_list_document_templates';
    }

    public function title(): string
    {
        return 'List document templates';
    }

    public function scope(): string
    {
        return 'documents:read';
    }

    public function description(): string
    {
        return 'Returns the document templates by name: {items} with the id, type_id, name and the number of the latest version, without its body. '
            .'Filter by type_id; archived templates only with include_archived. foresi_get_document_template gives the body and the variables a template can use.';
    }

    public function staffOnly(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type_id' => ToolArguments::idSchema('Only the templates of this document type, from foresi_list_document_types.'),
                'include_archived' => ['type' => 'boolean', 'default' => false, 'description' => 'Whether archived templates are listed too.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $templates = $this->reads->templates(ToolArguments::text($arguments, 'type_id'), ToolArguments::flag($arguments, 'include_archived'));
        } catch (DocumentsRefused $refused) {
            throw ToolRefusal::of($refused);
        }
        $count = \count($templates);

        return new ToolOutput(
            ['items' => array_map($this->json->templateRow(...), $templates)],
            1 === $count ? '1 document template.' : \sprintf('%d document templates.', $count),
        );
    }
}
