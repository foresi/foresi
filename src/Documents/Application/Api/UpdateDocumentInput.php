<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application\Api;

use Foresi\Documents\Domain\Document;
use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Omitted;
use Foresi\Shared\Application\Api\Operation;

#[Operation(
    name: 'documents.update',
    method: 'PATCH',
    path: '/api/v1/documents/{documentId}',
    tool: 'foresi_update_document',
    scope: 'documents:write',
    title: 'Change a draft document',
    description: 'Changes the title and links of a draft; a generated document is frozen and refused with not_draft.',
    response: 'DocumentsDocument',
    staffOnly: true,
)]
final readonly class UpdateDocumentInput
{
    /**
     * @param list<mixed>|Omitted $links
     */
    public function __construct(
        #[Field('The id of the document.', format: 'uuid')]
        public string $documentId,
        #[Field('The title.', maxLength: Document::MAX_TITLE)]
        public string|Omitted $title = new Omitted(),
        #[Field('The records the document belongs to, as objects {kind, id}, such as {"kind": "contact", "id": "..."}. A kind no module offers is refused. Replaces all links.', items: Field::OBJECTS)]
        public array|Omitted $links = new Omitted(),
    ) {
    }
}
