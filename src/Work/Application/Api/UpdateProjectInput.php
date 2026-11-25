<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Shared\Contract\Operation\Operation;
use Foresi\Work\Domain\Project;

/**
 * A partial change of a project over the API and MCP (ADR 0032): a field left
 * out keeps its value; the client sent as null is cleared.
 */
#[Operation(
    name: 'projects.update',
    method: 'PATCH',
    path: '/api/v1/projects/{projectId}',
    tool: 'foresi_update_project',
    scope: 'projects:write',
    title: 'Update a project',
    description: 'Changes the name, client or description of a project and keeps the rest; client_id sent as null leaves the project without a client. Only the owners and team members may (forbidden).',
    response: 'ProjectDetail',
)]
final readonly class UpdateProjectInput
{
    public function __construct(
        #[Field('The id of the project.', format: 'uuid')]
        public string $projectId,
        #[Field('The name of the project.', maxLength: Project::MAX_NAME_LENGTH)]
        public string|Omitted $name = new Omitted(),
        #[Field('The client, a contact as foresi_search_contacts returns it; null for none.', format: 'uuid')]
        public string|Omitted|null $clientId = new Omitted(),
        #[Field('What it is about, in Markdown; an empty text clears it.')]
        public string|Omitted $description = new Omitted(),
    ) {
    }
}
