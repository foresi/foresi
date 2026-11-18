<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Api;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * Pays all that remains of a recorded received document, today.
 */
#[Operation(
    name: 'received_documents.mark_paid',
    method: 'POST',
    path: '/api/v1/received-documents/{documentId}/mark-paid',
    tool: 'foresi_mark_received_document_paid',
    scope: 'received_documents:write',
    title: 'Mark a received document as paid',
    description: 'Records one payment of all that remains of a recorded received document, dated today in the organisation\'s time zone. A document paid in full already is refused with paid. To record a payment of another day or amount, use foresi_add_received_document_payment. Answers the document with its payments.',
    response: 'ReceivedDocumentDetail',
)]
final readonly class MarkReceivedDocumentPaidInput
{
    public function __construct(
        #[Field('The id of the received document.', format: 'uuid')]
        public string $documentId,
        #[Field('How it was paid.', enum: ['bank_transfer', 'card', 'cash', 'other'])]
        public string $method = 'bank_transfer',
        #[Field('The id of the bank movement that paid it, if known.', format: 'uuid')]
        public ?string $bankMovementId = null,
    ) {
    }
}
