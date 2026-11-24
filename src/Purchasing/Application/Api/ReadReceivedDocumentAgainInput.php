<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Api;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * Asks for the file of a draft to be read again over the API and MCP.
 */
#[Operation(
    name: 'received_documents.read_again',
    method: 'POST',
    path: '/api/v1/received-documents/{documentId}/read-again',
    tool: 'foresi_read_received_document_again',
    scope: 'received_documents:write',
    title: 'Read the file of a received document again',
    description: 'Queues the reading of the original file of a draft again, after a failure, when nothing was found, or to run the readers that only run on request; it answers at once with the draft and reading.state "reading". What the reading finds fills only the fields of the draft nobody changed since the request; everything else stays a suggestion in reading.values (applied false), and the draft is never recorded by it. '
        .'Poll foresi_get_received_document until reading.state is read, nothing_found or failed. A recorded document, one in the trash, a draft without a file, or a reading that was asked for less than 10 minutes ago and still runs is refused.',
    status: 202,
    response: 'ReceivedDocumentDetail',
    staffOnly: true,
)]
final readonly class ReadReceivedDocumentAgainInput
{
    public function __construct(
        #[Field('The id of the received document, a draft with an original file.', format: 'uuid')]
        public string $documentId,
    ) {
    }
}
