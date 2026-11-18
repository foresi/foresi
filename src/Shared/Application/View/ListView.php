<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

use Foresi\Shared\Domain\View\Placement;

/**
 * A view as a list shows it: its key in the list's address (the slug of a shipped
 * view, the id of anyone's own), its name to show, and its checked filter.
 */
final readonly class ListView
{
    public function __construct(
        public string $key,
        public string $name,
        public bool $translated,
        public ViewFilter $filter,
        public bool $showCount,
        public Placement $placement,
        public bool $private = false,
        public ?string $slug = null,
    ) {
    }

    public function isAll(): bool
    {
        return ListDefinition::ALL === $this->slug;
    }
}
