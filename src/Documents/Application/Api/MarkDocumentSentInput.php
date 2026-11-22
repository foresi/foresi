<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application\Api;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

#[Operation(
    name: 'documents.mark_sent',
    method: 'POST',
    path: '/api/v1/documents/{documentId}/mark-sent',
    tool: 'foresi_mark_document_sent',
    scope: 'documents:write',
    title: 'Mark a document sent for signature',
    description: 'Records that a ready document went out to its signers (by e-mail or on paper): it becomes out_for_signature.',
    response: 'DocumentsDocument',
    staffOnly: true,
)]
final readonly class MarkDocumentSentInput
{
    public function __construct(
        #[Field('The id of the document.', format: 'uuid')]
        public string $documentId,
    ) {
    }
}
