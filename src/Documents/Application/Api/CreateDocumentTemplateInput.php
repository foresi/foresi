<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application\Api;

use Foresi\Documents\Domain\DocumentTemplate;
use Foresi\Documents\Domain\TemplateVersion;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

#[Operation(
    name: 'documents.templates.create',
    method: 'POST',
    path: '/api/v1/document-templates',
    tool: 'foresi_create_document_template',
    scope: 'documents:write',
    title: 'Create a document template',
    description: 'Creates a template of a document type with its first version: the HTML body and the paper. A body outside the sandbox is refused with the construct in details.construct.',
    status: 201,
    response: 'DocumentsTemplate',
    staffOnly: true,
)]
final readonly class CreateDocumentTemplateInput
{
    public function __construct(
        #[Field('The id of the document type.', format: 'uuid')]
        public string $typeId,
        #[Field('The name of the template, such as "NDA, Czech".', maxLength: DocumentTemplate::MAX_NAME)]
        public string $name,
        #[Field('The HTML body with placeholders such as {{ client.name }}, in the subset of ADR 0042: the tags if, for and set and the filters escape, upper, lower, date, number_format, nl2br, default, join and length. foresi_get_document_template lists the variables.', maxLength: TemplateVersion::MAX_BODY)]
        public string $body,
        #[Field('The paper: a4 or letter.', enum: ['a4', 'letter'])]
        public string $paper = 'a4',
        #[Field('Whether the page is turned to landscape.')]
        public bool $landscape = false,
        #[Field('The top margin in whole millimetres, 0 to 60.')]
        public int $marginTop = 20,
        #[Field('The right margin in whole millimetres, 0 to 60.')]
        public int $marginRight = 20,
        #[Field('The bottom margin in whole millimetres, 0 to 60.')]
        public int $marginBottom = 20,
        #[Field('The left margin in whole millimetres, 0 to 60.')]
        public int $marginLeft = 20,
    ) {
    }
}
