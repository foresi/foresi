<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application\Api;

use Foresi\Documents\Domain\DocumentType;
use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

#[Operation(
    name: 'documents.types.update',
    method: 'PATCH',
    path: '/api/v1/document-types/{typeId}',
    tool: 'foresi_update_document_type',
    scope: 'documents:write',
    title: 'Change a document type',
    description: 'Changes the name, signing and signature level of a document type; the code stays. Documents already made keep their type.',
    response: 'DocumentsType',
    staffOnly: true,
)]
final readonly class UpdateDocumentTypeInput
{
    public function __construct(
        #[Field('The id of the document type.', format: 'uuid')]
        public string $typeId,
        #[Field('The name.', maxLength: DocumentType::MAX_NAME)]
        public string $name,
        #[Field('Whether documents of this type are signed.')]
        public bool $needsSigning,
        #[Field('The level of electronic signature.', enum: ['simple', 'advanced', 'qualified'])]
        public string $signatureLevel,
    ) {
    }
}
