<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

use Foresi\Shared\Domain\View\Placement;

/**
 * A shared view a module ships with a list (spec D12): app:views:sync stores it, and
 * the list shows it as declared until it is stored. Its slug names it in the list's
 * address (?view=paid) and makes its system key, "<list key>.<slug>". The name is a
 * translation key until someone renames the view.
 */
final readonly class DefaultView
{
    public function __construct(
        public string $slug,
        public string $name,
        public ViewFilter $filter = new ViewFilter(),
        public bool $showCount = true,
        public Placement $placement = Placement::Primary,
        public int $position = 0,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $slug)) {
            throw new \InvalidArgumentException(\sprintf('The slug "%s" of a default view is lowercase letters, digits and underscores.', $slug));
        }
    }
}
