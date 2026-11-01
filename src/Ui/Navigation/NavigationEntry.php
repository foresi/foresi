<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Navigation;

/**
 * An item as the sidebar shows it: resolved count and current-page flag.
 */
final readonly class NavigationEntry
{
    public function __construct(
        public string $labelKey,
        public string $icon,
        public string $route,
        public ?int $count,
        public bool $current,
        public ?string $commandLabelKey = null,
        public ?string $commandRoute = null,
    ) {
    }
}
