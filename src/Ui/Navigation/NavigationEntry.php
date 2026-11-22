<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Navigation;

/**
 * An item as the sidebar shows it: resolved count and current-page flag. An item
 * that marks its area puts the unread dot on the area in the rail while it counts.
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
        public ?int $countCap = null,
        public bool $marksArea = false,
    ) {
    }

    /**
     * The count as the sidebar writes it: above the cap, the cap with a plus.
     */
    public function countText(): ?string
    {
        if (null === $this->count) {
            return null;
        }

        return null !== $this->countCap && $this->count > $this->countCap ? $this->countCap.'+' : (string) $this->count;
    }
}
