<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract\Navigation;

use Foresi\Ui\Contract\Guard;

/**
 * One sidebar entry. The label is a translation key and the icon must be in the
 * locked icon set.
 *
 * Every item belongs to exactly one navigation area (see NavigationArea) and
 * names a section of that area. An item that names no section, or one its area
 * does not define, is listed in the area's last section; it is never an error,
 * so a plugin can never break the sidebar.
 *
 * The entry is marked as current on its own route and on every route whose name
 * starts with one of the prefixes, so the pages that belong to it keep it marked.
 * A count above the cap is shown as the cap with a plus, as "99+" of an inbox.
 * An item that marks its area puts a dot on the area in the rail while its count
 * is above zero, as an inbox with unread notifications does.
 */
final readonly class NavigationItem
{
    public function __construct(
        public string $area,
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
        public ?string $section = null,
        public bool $marksArea = false,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $area)) {
            throw new \InvalidArgumentException(sprintf('Navigation area id "%s" must be lowercase letters, digits and underscores.', $area));
        }
        if ('' === $labelKey || '' === $route) {
            throw new \InvalidArgumentException('A navigation item needs a label key and a route.');
        }
        if (null !== $countCap && $countCap < 1) {
            throw new \InvalidArgumentException('A count cap is a positive number.');
        }
        Guard::icon($icon);
    }
}
