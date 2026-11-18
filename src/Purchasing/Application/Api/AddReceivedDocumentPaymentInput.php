<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Api;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * One payment of a recorded received document over the API and MCP.
 */
#[Operation(
    name: 'received_documents.add_payment',
    method: 'POST',
    path: '/api/v1/received-documents/{documentId}/payments',
    tool: 'foresi_add_received_document_payment',
    scope: 'received_documents:write',
    title: 'Add a payment of a received document',
    description: 'Records that the firm paid a recorded received document, or part of it: the day, the amount in the document currency and how. A document may have several payments; they may not add up to more than its total. For a credit note the amount is what the supplier returned, positive. To pay all that remains today, use foresi_mark_received_document_paid. Answers the document with its payments and what remains.',
    status: 201,
    response: 'ReceivedDocumentDetail',
)]
final readonly class AddReceivedDocumentPaymentInput
{
    public function __construct(
        #[Field('The id of the received document.', format: 'uuid')]
        public string $documentId,
        #[Field('The day it was paid, YYYY-MM-DD.', format: 'date')]
        public string $paidOn,
        #[Field('The amount paid, in the currency of the document, such as "1210.00".')]
        public string $amount,
        #[Field('How it was paid.', enum: ['bank_transfer', 'card', 'cash', 'other'])]
        public string $method = 'bank_transfer',
        #[Field('The id of the bank movement that paid it, if known.', format: 'uuid')]
        public ?string $bankMovementId = null,
    ) {
    }
}
