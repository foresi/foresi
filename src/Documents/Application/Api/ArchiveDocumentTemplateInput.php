<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

#[Operation(
    name: 'documents.templates.archive',
    method: 'POST',
    path: '/api/v1/document-templates/{templateId}/archive',
    tool: 'foresi_archive_document_template',
    scope: 'documents:write',
    title: 'Archive a document template',
    description: 'Archives a template: no new documents are made from it; documents made from it stay.',
    response: 'DocumentsTemplate',
    staffOnly: true,
)]
final readonly class ArchiveDocumentTemplateInput
{
    public function __construct(
        #[Field('The id of the template.', format: 'uuid')]
        public string $templateId,
    ) {
    }
}
