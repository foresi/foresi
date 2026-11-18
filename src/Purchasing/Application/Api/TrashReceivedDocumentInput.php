<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Api;

use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * Moves a received document to the trash over the API and MCP.
 */
#[Operation(
    name: 'received_documents.trash',
    method: 'POST',
    path: '/api/v1/received-documents/{documentId}/trash',
    tool: 'foresi_trash_received_document',
    scope: 'received_documents:write',
    title: 'Trash a received document',
    description: 'Moves a received document to the trash with a reason: it keeps its evidence number and content, leaves the lists and the VAT reports, and takes no change or payment until a person restores it in Foresi. A document of a closed month is refused with locked.',
    destructive: true,
    response: 'ReceivedDocumentDetail',
)]
final readonly class TrashReceivedDocumentInput
{
    public function __construct(
        #[Field('The id of the received document.', format: 'uuid')]
        public string $documentId,
        #[Field('Why it goes to the trash, kept in its history.', maxLength: ReceivedDocument::MAX_REASON_LENGTH)]
        public string $reason,
    ) {
    }
}
