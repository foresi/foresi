<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application\Api;

use App\Shared\Application\Api\Field;
use App\Shared\Application\Api\Operation;

/**
 * Removes a payment of a received document recorded by mistake.
 */
#[Operation(
    name: 'received_documents.remove_payment',
    method: 'DELETE',
    path: '/api/v1/received-documents/{documentId}/payments/{paymentId}',
    tool: 'foresi_remove_received_document_payment',
    scope: 'received_documents:write',
    title: 'Remove a payment of a received document',
    description: 'Removes one payment of a received document that was recorded by mistake, such as a wrong day or amount; then record the right one. The document has that much more to pay again. Find the payment id with foresi_list_received_document_payments. Answers the document with the payments it has left.',
    destructive: true,
    response: 'ReceivedDocumentDetail',
)]
final readonly class RemoveReceivedDocumentPaymentInput
{
    public function __construct(
        #[Field('The id of the received document.', format: 'uuid')]
        public string $documentId,
        #[Field('The id of the payment to remove.', format: 'uuid')]
        public string $paymentId,
    ) {
    }
}
