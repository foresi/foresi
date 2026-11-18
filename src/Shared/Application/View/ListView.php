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
        /** @var list<array{field: string, dir: string}> none for the list's own order */
        public array $sort = [],
        /** The id of the stored view; null for a view the module ships that is not stored yet. */
        public ?string $id = null,
    ) {
    }

    /**
     * What SavedViews::get() finds the view by: its id, or the system key of a
     * shipped view that is not stored yet.
     */
    public function ref(string $listKey): string
    {
        return $this->id ?? $listKey.'.'.$this->slug;
    }

    public function isAll(): bool
    {
        return ListDefinition::ALL === $this->slug;
    }
}
