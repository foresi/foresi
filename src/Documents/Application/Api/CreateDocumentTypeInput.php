<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application\Api;

use Foresi\Documents\Domain\DocumentType;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

#[Operation(
    name: 'documents.types.create',
    method: 'POST',
    path: '/api/v1/document-types',
    tool: 'foresi_create_document_type',
    scope: 'documents:write',
    title: 'Create a document type',
    description: 'Creates a kind of document, such as a service contract: its name, a unique code that never changes, and whether it needs signing and at which level. Templates and documents are of a type.',
    status: 201,
    response: 'DocumentsType',
    staffOnly: true,
)]
final readonly class CreateDocumentTypeInput
{
    public function __construct(
        #[Field('The name, such as "Service contract".', maxLength: DocumentType::MAX_NAME)]
        public string $name,
        #[Field('A unique lowercase code, such as "service_contract"; it never changes.', maxLength: DocumentType::MAX_CODE)]
        public string $code,
        #[Field('Whether documents of this type are signed.')]
        public bool $needsSigning = true,
        #[Field('The level of electronic signature: simple, advanced or qualified.', enum: ['simple', 'advanced', 'qualified'])]
        public string $signatureLevel = 'simple',
    ) {
    }
}
