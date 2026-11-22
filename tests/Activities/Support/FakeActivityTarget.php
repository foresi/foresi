<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Activities\Support;

use Foresi\Activities\Contract\ActivityTarget;

/**
 * Records of one kind kept in memory. One can be taken away, as an archived or
 * deleted record is, or hidden from the person, as a deal of someone else's.
 */
final class FakeActivityTarget implements ActivityTarget
{
    /** @var array<string, string> name by id */
    private array $records = [];

    /** @var array<string, true> */
    private array $hidden = [];

    public function __construct(private readonly string $kind = 'contact')
    {
    }

    public function add(string $id, string $name): void
    {
        $this->records[$id] = $name;
    }

    public function remove(string $id): void
    {
        unset($this->records[$id]);
    }

    public function hide(string $id): void
    {
        $this->hidden[$id] = true;
    }

    public function kinds(): array
    {
        return [$this->kind];
    }

    public function canSee(string $kind, string $id): bool
    {
        return $this->kind === $kind && isset($this->records[$id]) && !isset($this->hidden[$id]);
    }

    public function canLink(string $kind, string $id): bool
    {
        return $this->canSee($kind, $id);
    }

    public function label(string $kind, string $id): ?string
    {
        return $this->canSee($kind, $id) ? $this->records[$id] : null;
    }

    public function url(string $kind, string $id): ?string
    {
        return $this->canSee($kind, $id) ? '/'.$kind.'s/'.$id : null;
    }
}
