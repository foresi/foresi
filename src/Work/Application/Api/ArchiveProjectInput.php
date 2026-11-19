<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * Archives a project over the API and MCP.
 */
#[Operation(
    name: 'projects.archive',
    method: 'POST',
    path: '/api/v1/projects/{projectId}/archive',
    tool: 'foresi_archive_project',
    scope: 'projects:write',
    title: 'Archive a project',
    description: 'Archives a project: it keeps its tasks and time, leaves the lists of active projects and takes no new tasks. Only the owners and team members may. Archiving an archived project changes nothing. Bring it back with foresi_restore_project.',
    response: 'ProjectDetail',
)]
final readonly class ArchiveProjectInput
{
    public function __construct(
        #[Field('The id of the project.', format: 'uuid')]
        public string $projectId,
    ) {
    }
}
