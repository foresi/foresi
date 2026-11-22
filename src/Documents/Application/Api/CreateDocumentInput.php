<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application\Api;

use Foresi\Documents\Domain\Document;
use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

#[Operation(
    name: 'documents.create',
    method: 'POST',
    path: '/api/v1/documents',
    tool: 'foresi_create_document',
    scope: 'documents:write',
    title: 'Create a document',
    description: 'Creates a draft document of a type from a template, linked to the records it belongs to, such as the contact it is for. Then add signers with foresi_add_document_signer and generate it with foresi_generate_document.',
    status: 201,
    response: 'DocumentsDocument',
    staffOnly: true,
)]
final readonly class CreateDocumentInput
{
    /**
     * @param list<mixed> $links
     */
    public function __construct(
        #[Field('The id of the document type.', format: 'uuid')]
        public string $typeId,
        #[Field('The id of the template.', format: 'uuid')]
        public string $templateId,
        #[Field('The title, such as "NDA with Example Ltd".', maxLength: Document::MAX_TITLE)]
        public string $title,
        #[Field('The records the document belongs to, as objects {kind, id}, such as {"kind": "contact", "id": "..."}. A kind no module offers is refused.', items: Field::OBJECTS)]
        public array $links = [],
    ) {
    }
}
