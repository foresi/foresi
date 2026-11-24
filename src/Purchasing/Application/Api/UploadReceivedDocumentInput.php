<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Api;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * A file of a received document sent over the API and MCP as base64: it becomes a draft
 * and is read in the background, as a file dropped on the screen.
 */
#[Operation(
    name: 'received_documents.upload',
    method: 'POST',
    path: '/api/v1/received-documents/upload',
    tool: 'foresi_upload_received_document',
    scope: 'received_documents:write',
    title: 'Upload a received document',
    description: 'Makes a draft of a received document from its file, one file per call, up to 10 MB: a PDF, an image (PNG, JPEG, GIF, WebP, HEIC), an ISDOC file (.isdoc, .isdocx) or XML, sent as base64. It answers at once with the draft (source "api", reading.state "reading"); the file is read in the background, and what it finds fills the draft while nobody has changed it. '
        .'Poll foresi_get_received_document until reading.state is read, nothing_found or failed. If you already know the values, you may edit the draft or record the document yourself: a reading never overwrites what was changed after the upload. A file Foresi refuses makes no draft. Search the documents for the supplier number first to avoid uploading one twice.',
    status: 201,
    response: 'ReceivedDocumentDetail',
    staffOnly: true,
)]
final readonly class UploadReceivedDocumentInput
{
    public function __construct(
        #[Field('The file name with its extension, such as "invoice.isdoc" or "scan.pdf"; the extension tells an .isdocx from another ZIP.', maxLength: 255)]
        public string $fileName,
        #[Field('The file, base64 encoded; up to 10 MB decoded.')]
        public string $contentBase64,
    ) {
    }
}
