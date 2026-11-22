<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract\Navigation;

/**
 * A section of a navigation area: a heading in the area's sidebar that items
 * are listed under. The area defines its sections and their order; an item
 * names the section it belongs to (see NavigationItem). The label is a
 * translation key, and sections are shown by weight, then by the order the
 * area lists them in.
 */
final readonly class NavigationSection
{
    /** The key of the one section an area gets when it defines none. */
    public const string DEFAULT = 'main';

    public function __construct(
        public string $key,
        public string $labelKey,
        public int $weight = 100,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw new \InvalidArgumentException(sprintf('Navigation section key "%s" must be lowercase letters, digits and underscores.', $key));
        }
        if ('' === $labelKey) {
            throw new \InvalidArgumentException('A navigation section needs a label key.');
        }
    }
}
