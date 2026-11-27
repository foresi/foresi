<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Support;

/**
 * A row of a short table the test keeps in memory: a name, in the trash when it has a deletion time.
 */
final readonly class TestRow
{
    public function __construct(
        public string $id,
        public string $name,
        public ?\DateTimeImmutable $deletedAt = null,
    ) {
    }

    public function isTrashed(): bool
    {
        return $this->deletedAt instanceof \DateTimeImmutable;
    }
}
