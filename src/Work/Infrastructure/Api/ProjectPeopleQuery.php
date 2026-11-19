<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * The query string of GET /api/v1/projects/{id}/people.
 */
final readonly class ProjectPeopleQuery
{
    public function __construct(
        #[SerializedName('task_id')]
        public ?string $taskId = null,
    ) {
    }
}
