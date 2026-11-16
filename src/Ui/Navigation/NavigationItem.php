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
 * A count above the cap is shown as the cap with a plus, as "99+" of an inbox.
 *
 * Every item belongs to exactly one navigation area (see NavigationArea). Inside
 * the area the group becomes the section the item is listed under. Groups that
 * map to one area only give the area on their own; "money" spans purchasing and
 * money, so its items, like those of a plugin group, name the area explicitly.
 */
final readonly class NavigationItem
{
    /** Groups in display order; "main" has no heading and "settings" is shown at the bottom of the sidebar. */
    public const array GROUPS = ['main', 'sales', 'money', 'contacts', 'work', 'extensions', 'settings'];

    /** The area of a group whose items all sit in one area. */
    private const array GROUP_AREAS = ['main' => 'overview', 'sales' => 'sales', 'contacts' => 'contacts', 'work' => 'projects', 'settings' => NavigationArea::SETTINGS];

    public string $area;

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
        public ?int $countCap = null,
        ?string $area = null,
    ) {
        if (!\in_array($groupKey, self::GROUPS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown navigation group "%s", expected one of: %s.', $groupKey, implode(', ', self::GROUPS)));
        }
        if ('' === $labelKey || '' === $route) {
            throw new \InvalidArgumentException('A navigation item needs a label key and a route.');
        }
        if (null !== $countCap && $countCap < 1) {
            throw new \InvalidArgumentException('A count cap is a positive number.');
        }
        Style::icon($icon);
        $area ??= self::GROUP_AREAS[$groupKey] ?? throw new \InvalidArgumentException(sprintf('A navigation item in group "%s" needs an area.', $groupKey));
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $area)) {
            throw new \InvalidArgumentException(sprintf('Navigation area id "%s" must be lowercase letters, digits and underscores.', $area));
        }
        $this->area = $area;
    }
}
