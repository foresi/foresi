<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

#[Operation(
    name: 'documents.generate_again',
    method: 'POST',
    path: '/api/v1/documents/{documentId}/generate-again',
    tool: 'foresi_generate_document_again',
    scope: 'documents:write',
    title: 'Make a new draft of a document',
    description: 'Makes a new draft from a completed, declined or cancelled document: the same type, template, title, links and signers (pending again). It is generated from the latest template version.',
    status: 201,
    response: 'DocumentsDocument',
    staffOnly: true,
)]
final readonly class GenerateDocumentAgainInput
{
    public function __construct(
        #[Field('The id of the document.', format: 'uuid')]
        public string $documentId,
    ) {
    }
}
