<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

use Foresi\Shared\Domain\View\Placement;

/**
 * A view as a list shows it: its key in the list's address (the slug of a shipped
 * view, the id of anyone's own), its name to show, its checked filter and its
 * display payload as stored (the list columns read it, D-display-2; null for the
 * list's defaults).
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
        /** @var array<array-key, mixed>|null */
        public ?array $display = null,
    ) {
    }

    public function isAll(): bool
    {
        return ListDefinition::ALL === $this->slug;
    }
}
