<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

#[Operation(
    name: 'documents.signers.mark_signed',
    method: 'POST',
    path: '/api/v1/documents/{documentId}/signers/{signerId}/mark-signed',
    tool: 'foresi_mark_document_signer_signed',
    scope: 'documents:write',
    title: 'Mark a signer signed',
    description: 'Records that a signer signed a ready or sent document; from ready it also marks it sent. Once every signer signed and the signed copy is attached, the document is completed.',
    response: 'DocumentsDocument',
    staffOnly: true,
)]
final readonly class MarkSignerSignedInput
{
    public function __construct(
        #[Field('The id of the document.', format: 'uuid')]
        public string $documentId,
        #[Field('The id of the signer.', format: 'uuid')]
        public string $signerId,
    ) {
    }
}
