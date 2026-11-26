<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A cancelled document put in the trash over the API and MCP. Doing it twice
 * changes nothing.
 */
#[Operation(
    name: 'documents.trash',
    method: 'POST',
    path: '/api/v1/documents/{documentId}/trash',
    tool: 'foresi_trash_document',
    scope: 'documents:write',
    title: 'Put a cancelled document in the trash',
    description: 'Puts a cancelled document in the trash: it leaves the document lists and the records it is linked to, and keeps its history, files and frozen template version; restore it with foresi_restore_document. Only a cancelled document goes to the trash; trashing it again changes nothing.',
    destructive: true,
    response: 'DocumentsDocument',
    staffOnly: true,
)]
final readonly class TrashDocumentInput
{
    public function __construct(
        #[Field('The id of the document.', format: 'uuid')]
        public string $documentId,
    ) {
    }
}
