<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application\Api;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

#[Operation(
    name: 'documents.cancel',
    method: 'POST',
    path: '/api/v1/documents/{documentId}/cancel',
    tool: 'foresi_cancel_document',
    scope: 'documents:write',
    title: 'Cancel a document',
    description: 'Cancels a document that is not completed, declined or cancelled; it keeps its history and files and cannot be taken back. Generate it again for a new draft.',
    destructive: true,
    response: 'DocumentsDocument',
    staffOnly: true,
)]
final readonly class CancelDocumentInput
{
    public function __construct(
        #[Field('The id of the document.', format: 'uuid')]
        public string $documentId,
    ) {
    }
}
