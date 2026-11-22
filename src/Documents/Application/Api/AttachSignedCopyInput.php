<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application\Api;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

#[Operation(
    name: 'documents.attach_signed_copy',
    method: 'POST',
    path: '/api/v1/documents/{documentId}/signed-copy',
    tool: 'foresi_attach_signed_document_copy',
    scope: 'documents:write',
    title: 'Attach the signed copy of a document',
    description: 'Stores the signed copy of a ready or sent document, a PDF up to 10 MB sent as base64; from ready it also marks it sent. Once every signer is marked signed too, the document is completed.',
    response: 'DocumentsDocument',
    staffOnly: true,
)]
final readonly class AttachSignedCopyInput
{
    public function __construct(
        #[Field('The id of the document.', format: 'uuid')]
        public string $documentId,
        #[Field('The file name, such as "nda-signed.pdf".', maxLength: 255)]
        public string $fileName,
        #[Field('The PDF, base64 encoded.')]
        public string $contentBase64,
    ) {
    }
}
