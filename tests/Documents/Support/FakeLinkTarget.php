<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Documents\Support;

use Foresi\Documents\Contract\DocumentLinkTarget;

/**
 * Contacts kept in memory; one can be taken away, as an archived or trashed record is.
 */
final class FakeLinkTarget implements DocumentLinkTarget
{
    /** @var array<string, string> name by id */
    private array $records = [];

    public function add(string $id, string $name): void
    {
        $this->records[$id] = $name;
    }

    public function remove(string $id): void
    {
        unset($this->records[$id]);
    }

    public function kinds(): array
    {
        return ['contact'];
    }

    public function exists(string $kind, string $id): bool
    {
        return 'contact' === $kind && isset($this->records[$id]);
    }

    public function label(string $kind, string $id): ?string
    {
        return $this->records[$id] ?? null;
    }

    public function url(string $kind, string $id): ?string
    {
        return isset($this->records[$id]) ? '/contacts/'.$id : null;
    }

    public function name(string $id): ?string
    {
        return $this->records[$id] ?? null;
    }
}
