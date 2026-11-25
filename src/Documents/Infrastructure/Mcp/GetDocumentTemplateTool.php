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
 * One document template, as GET /api/v1/document-templates/{templateId} writes it.
 */
final readonly class GetDocumentTemplateTool implements ReadTool, StaffOnlyTool
{
    public function __construct(private DocumentReads $reads, private DocumentsJson $json)
    {
    }

    public function name(): string
    {
        return 'foresi_get_document_template';
    }

    public function title(): string
    {
        return 'Get a document template';
    }

    public function scope(): string
    {
        return 'documents:read';
    }

    public function description(): string
    {
        return 'Returns one document template: its latest version with the HTML body, the paper and the variables the body uses (uses), its versions (a frozen one is kept for the documents made from it), '
            .'and every variable a body can use with its label, an example and whether it is an image, such as firm.name; an image variable such as firm.logo prints an <img> of a local file. Change the body with foresi_save_document_template_version.';
    }

    public function staffOnly(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['template_id' => ToolSchema::idSchema('The id of the template, from foresi_list_document_templates.')],
            'required' => ['template_id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $template = $this->reads->template((string) ToolSchema::text($arguments, 'template_id'));
        } catch (DocumentsRefused $refused) {
            throw ToolRefusal::of($refused);
        }

        return new ToolOutput($this->json->template($template), \sprintf('The template "%s".', $template->name()));
    }
}
