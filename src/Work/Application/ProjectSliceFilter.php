<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Shared\Application\View\ViewFilter;

/**
 * What a slice of the projects is narrowed to, as GET /api/v1/projects and
 * its tool ask for it.
 */
final readonly class ProjectSliceFilter
{
    /**
     * @param bool            $archived the archived projects instead of the active ones, unless the view has a condition on archived
     * @param ViewFilter|null $view     the conditions of a saved view of the list work.projects, and of a filter
     */
    public function __construct(
        public string $text,
        public bool $archived,
        public ?string $clientId,
        public ?ViewFilter $view,
    ) {
    }
}
