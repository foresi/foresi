<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application\Api;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

#[Operation(
    name: 'documents.signers.remove',
    method: 'DELETE',
    path: '/api/v1/documents/{documentId}/signers/{signerId}',
    tool: 'foresi_remove_document_signer',
    scope: 'documents:write',
    title: 'Remove a signer from a document',
    description: 'Removes a signer from a document that is not completed, declined or cancelled.',
    destructive: true,
    response: 'DocumentsDocument',
    staffOnly: true,
)]
final readonly class RemoveSignerInput
{
    public function __construct(
        #[Field('The id of the document.', format: 'uuid')]
        public string $documentId,
        #[Field('The id of the signer.', format: 'uuid')]
        public string $signerId,
    ) {
    }
}
