<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

#[Operation(
    name: 'documents.signers.mark_declined',
    method: 'POST',
    path: '/api/v1/documents/{documentId}/signers/{signerId}/mark-declined',
    tool: 'foresi_mark_document_signer_declined',
    scope: 'documents:write',
    title: 'Mark a signer declined',
    description: 'Records that a signer declined to sign; the document becomes declined and changes no more.',
    destructive: true,
    response: 'DocumentsDocument',
    staffOnly: true,
)]
final readonly class MarkSignerDeclinedInput
{
    public function __construct(
        #[Field('The id of the document.', format: 'uuid')]
        public string $documentId,
        #[Field('The id of the signer.', format: 'uuid')]
        public string $signerId,
    ) {
    }
}
