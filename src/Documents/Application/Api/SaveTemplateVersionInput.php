<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application\Api;

use Foresi\Documents\Domain\TemplateVersion;
use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

#[Operation(
    name: 'documents.templates.save_version',
    method: 'POST',
    path: '/api/v1/document-templates/{templateId}/versions',
    tool: 'foresi_save_document_template_version',
    scope: 'documents:write',
    title: 'Save a document template',
    description: 'Saves the body and paper of a template. The latest version is changed in place until a document is generated from it; after that it is frozen and this makes the next version. Documents already generated keep their version.',
    response: 'DocumentsTemplate',
    staffOnly: true,
)]
final readonly class SaveTemplateVersionInput
{
    public function __construct(
        #[Field('The id of the template.', format: 'uuid')]
        public string $templateId,
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
