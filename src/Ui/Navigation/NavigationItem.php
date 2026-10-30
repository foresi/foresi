<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Navigation;

use App\Ui\Component\Style;

/**
 * One sidebar entry. The label is a translation key, the icon must be in the
 * locked icon set and the group one of the fixed ones.
 */
final readonly class NavigationItem
{
    /** Groups in display order; "settings" is shown at the bottom of the sidebar. */
    public const array GROUPS = ['main', 'sales', 'money', 'contacts', 'extensions', 'settings'];

    public function __construct(
        public string $groupKey,
        public string $labelKey,
        public string $icon,
        public string $route,
        public ?NavigationCountProvider $countProvider = null,
        public int $weight = 100,
    ) {
        if (!\in_array($groupKey, self::GROUPS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown navigation group "%s", expected one of: %s.', $groupKey, implode(', ', self::GROUPS)));
        }
        if ('' === $labelKey || '' === $route) {
            throw new \InvalidArgumentException('A navigation item needs a label key and a route.');
        }
        Style::icon($icon);
    }
}
