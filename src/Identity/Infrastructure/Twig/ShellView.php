<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Twig;

use App\Identity\Application\Organisation;

/**
 * What the app shell shows about the signed-in user and the active organisation.
 */
final readonly class ShellView
{
    /**
     * @param list<Organisation> $organisations
     */
    public function __construct(
        public string $userName,
        public string $userEmail,
        public string $initials,
        public string $locale,
        public string $otherLocale,
        public bool $tenantActive,
        public ?Organisation $organisation,
        public array $organisations,
    ) {
    }
}
