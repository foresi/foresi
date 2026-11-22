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
 * One document, as GET /api/v1/documents/{documentId} writes it.
 */
final readonly class GetDocumentTool implements ReadTool, StaffOnlyTool
{
    public function __construct(private DocumentReads $reads, private DocumentsJson $json)
    {
    }

    public function name(): string
    {
        return 'foresi_get_document';
    }

    public function title(): string
    {
        return 'Get a document';
    }

    public function scope(): string
    {
        return 'documents:read';
    }

    public function description(): string
    {
        return 'Returns one document: its status, type, template version, links (a link whose record is gone is available: false), signers in signing order with their status, '
            .'the stored files (pdf, signed, certificate) with their SHA-256, and the values frozen when it was generated. '
            .'The files download from GET /api/v1/documents/{document_id}/files/{pdf|signed|certificate}. '
            .'Move it on with foresi_generate_document, foresi_mark_document_sent, foresi_attach_signed_document_copy, foresi_mark_document_signer_signed or foresi_cancel_document.';
    }

    public function staffOnly(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['document_id' => ToolArguments::idSchema('The id of the document, from foresi_list_documents.')],
            'required' => ['document_id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $document = $this->reads->document((string) ToolArguments::text($arguments, 'document_id'));
        } catch (DocumentsRefused $refused) {
            throw ToolRefusal::of($refused);
        }

        return new ToolOutput($this->json->document($document), \sprintf('The document "%s" is %s.', $document->title(), $document->status()->value));
    }
}
