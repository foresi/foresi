<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Application\Field\CustomFieldsOf;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Shared\Contract\Operation\Operation;
use Foresi\Work\Contract\Field\ProjectExtension;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\WorkflowTemplate;

/**
 * A new project over the API and MCP, as the projects screen starts one.
 */
#[Operation(
    name: 'projects.create',
    method: 'POST',
    path: '/api/v1/projects',
    tool: 'foresi_create_project',
    scope: 'projects:write',
    title: 'Create a project',
    description: 'Starts a project open to the organisation, with the statuses of its workflow: simple or development, which has more steps. Only the owners and team members may (forbidden). Its sharing and sections are kept on the screens.',
    status: 201,
    response: 'ProjectDetail',
)]
final readonly class CreateProjectInput
{
    /**
     * @param array<string, mixed>|Omitted $customFields
     */
    public function __construct(
        #[Field('The name of the project.', maxLength: Project::MAX_NAME_LENGTH)]
        public string $name,
        #[Field('The client, a contact as foresi_search_contacts returns it; none when left out.', format: 'uuid')]
        public ?string $clientId = null,
        #[Field('The statuses it starts with.', enum: ['simple', 'development'])]
        public string $workflow = WorkflowTemplate::Simple->value,
        #[Field('What it is about, in Markdown.')]
        public string $description = '',
        #[Field('The custom fields by key, as foresi_list_fields lists them for projects; required ones need a value.', items: Field::ONE_OBJECT)]
        #[CustomFieldsOf(ProjectExtension::RECORD)]
        public array|Omitted $customFields = new Omitted(),
    ) {
    }
}
