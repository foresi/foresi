<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api;

use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Work\Application\ProjectSliceFilter;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query string of GET /api/v1/projects. Every parameter narrows the list.
 */
final readonly class ProjectListQuery
{
    public function __construct(
        public string $q = '',
        public bool $archived = false,
        #[SerializedName('client_id')]
        public ?string $clientId = null,
        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 25,
        public ?string $cursor = null,
        // A saved view of the list work.projects: its id or system key, such as "work.projects.archived".
        public ?string $view = null,
        public ?string $filter = null,
    ) {
    }

    public function filter(?ViewFilter $view = null): ProjectSliceFilter
    {
        return new ProjectSliceFilter(trim($this->q), $this->archived, $this->clientId, $view);
    }
}
