<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Doctrine;

use Foresi\Party\Application\ContactSorts;

/**
 * Without the address book (the contacts module), contacts sort by their name
 * and no other module adds a column to read.
 */
final readonly class ByNameContactSorts implements ContactSorts
{
    public function sorts(): array
    {
        return [];
    }

    public function join(string $key): string
    {
        return '';
    }

    public function load(array $ids): void
    {
    }
}
