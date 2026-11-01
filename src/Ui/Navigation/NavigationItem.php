<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Navigation;

use App\Ui\Component\Style;

/**
 * One sidebar entry. The label is a translation key, the icon must be in the
 * locked icon set and the group one of the fixed ones. A group without items is
 * not shown, so a module that is not installed leaves no heading behind.
 *
 * The entry is marked as current on its own route and on every route whose name
 * starts with one of the prefixes, so the pages that belong to it keep it marked.
 */
final readonly class NavigationItem
{
    /** Groups in display order; "main" has no heading and "settings" is shown at the bottom of the sidebar. */
    public const array GROUPS = ['main', 'sales', 'money', 'contacts', 'work', 'extensions', 'settings'];

    public function __construct(
        public string $groupKey,
        public string $labelKey,
        public string $icon,
        public string $route,
        public ?NavigationCountProvider $countProvider = null,
        public int $weight = 100,
        public ?string $commandLabelKey = null,
        public ?string $commandRoute = null,
        /** @var list<string> */
        public array $routePrefixes = [],
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
