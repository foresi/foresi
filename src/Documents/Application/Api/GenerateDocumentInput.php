<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application\Api;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

#[Operation(
    name: 'documents.generate',
    method: 'POST',
    path: '/api/v1/documents/{documentId}/generate',
    tool: 'foresi_generate_document',
    scope: 'documents:write',
    title: 'Generate a document',
    description: 'Fills the latest version of the template with the values of the linked records, renders the PDF and freezes it, the values and the version: the draft becomes ready. A variable with no value is refused with the names in details.missing, and the document stays a draft.',
    response: 'DocumentsDocument',
    staffOnly: true,
)]
final readonly class GenerateDocumentInput
{
    public function __construct(
        #[Field('The id of the document.', format: 'uuid')]
        public string $documentId,
    ) {
    }
}
