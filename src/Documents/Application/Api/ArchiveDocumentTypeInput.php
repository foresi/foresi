<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application\Api;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

#[Operation(
    name: 'documents.types.archive',
    method: 'POST',
    path: '/api/v1/document-types/{typeId}/archive',
    tool: 'foresi_archive_document_type',
    scope: 'documents:write',
    title: 'Archive a document type',
    description: 'Archives a document type: no new templates or documents are made of it; its documents stay.',
    response: 'DocumentsType',
    staffOnly: true,
)]
final readonly class ArchiveDocumentTypeInput
{
    public function __construct(
        #[Field('The id of the document type.', format: 'uuid')]
        public string $typeId,
    ) {
    }
}
