<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * The quick assignment: the whole cost of a received document to one recipient.
 */
#[Operation(
    name: 'margin.documents.assign',
    method: 'POST',
    path: '/api/v1/margin/documents/{documentId}/assign',
    tool: 'foresi_assign_received_document',
    scope: 'margin:write',
    title: 'Assign a received document to a client',
    description: 'Gives the whole cost of a recorded received document to one recipient: "own" for the firm itself, or the id of a contact, a client (find it with foresi_search_contacts). It applies to every line that has no mapping of its own (foresi_set_line_allocation), and the shares are counted at once. recipient null clears the assignment. '
        .'Answers the document with its lines and shares.',
    response: 'MarginDocumentAllocation',
    staffOnly: true,
)]
final readonly class AssignDocumentInput
{
    public function __construct(
        #[Field('The id of the received document.', format: 'uuid')]
        public string $documentId,
        #[Field('"own" for the firm itself, or the id of the contact that bears the cost; null clears the assignment.', maxLength: 36)]
        public ?string $recipient,
    ) {
    }
}
