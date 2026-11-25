<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\Api;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * Takes a project out of the archive over the API and MCP.
 */
#[Operation(
    name: 'projects.restore',
    method: 'POST',
    path: '/api/v1/projects/{projectId}/restore',
    tool: 'foresi_restore_project',
    scope: 'projects:write',
    title: 'Restore a project',
    description: 'Takes a project out of the archive, so it is active again. Restoring an active project changes nothing.',
    response: 'ProjectDetail',
)]
final readonly class RestoreProjectInput
{
    public function __construct(
        #[Field('The id of the project.', format: 'uuid')]
        public string $projectId,
    ) {
    }
}
