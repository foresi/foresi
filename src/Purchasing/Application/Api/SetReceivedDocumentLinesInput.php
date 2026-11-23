<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Api;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * The lines of a received document over the API and MCP, all at once.
 */
#[Operation(
    name: 'received_documents.lines.set',
    method: 'PUT',
    path: '/api/v1/received-documents/{documentId}/lines',
    tool: 'foresi_set_received_document_lines',
    scope: 'received_documents:write',
    title: 'Set the lines of a received document',
    description: 'Replaces the lines of a draft or recorded received document: what it was for, split by the rates of its VAT recap. '
        .'Each line is {text, base, vat_rate}: the base is a decimal in the document currency without VAT, such as "1000.00", and vat_rate a rate of the recap, such as "21". '
        .'Per rate the bases must add up to the base of that rate in the recap (lines_do_not_add_up otherwise); a document without a recap has the one rate "0", and its lines add up to its total. '
        .'The lines never change the recap, the VAT or the total. An empty list removes the lines; a document without lines answers one line per rate of its recap. '
        .'A document in the trash or of a closed month is refused. Answers the document with its lines.',
    response: 'ReceivedDocumentDetail',
)]
final readonly class SetReceivedDocumentLinesInput
{
    /**
     * @param list<mixed> $lines
     */
    public function __construct(
        #[Field('The id of the received document.', format: 'uuid')]
        public string $documentId,
        #[Field('The lines, in their order, replacing all of them: objects of text (at most 255 characters), base (a decimal in the document currency, such as "1000.00") and vat_rate (a rate of the VAT recap, such as "21"); at most 100. An empty list removes them.', items: Field::OBJECTS)]
        public array $lines,
    ) {
    }
}
