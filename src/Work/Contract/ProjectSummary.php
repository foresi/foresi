<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Contract;

/**
 * One project as another module shows it: its name, its client and a link to its page.
 */
final readonly class ProjectSummary
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $clientId,
        public bool $archived,
        /** The address of the project's page, relative to the site. */
        public string $url,
    ) {
    }
}
