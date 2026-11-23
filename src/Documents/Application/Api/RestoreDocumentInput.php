<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application\Api;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * A document brought back from the trash over the API and MCP. One that is not
 * there changes nothing.
 */
#[Operation(
    name: 'documents.restore',
    method: 'POST',
    path: '/api/v1/documents/{documentId}/restore',
    tool: 'foresi_restore_document',
    scope: 'documents:write',
    title: 'Restore a document from the trash',
    description: 'Brings a document back from the trash, cancelled as it was, into the document lists and the records it is linked to. Restoring one that is not in the trash changes nothing.',
    response: 'DocumentsDocument',
    staffOnly: true,
)]
final readonly class RestoreDocumentInput
{
    public function __construct(
        #[Field('The id of the document.', format: 'uuid')]
        public string $documentId,
    ) {
    }
}
