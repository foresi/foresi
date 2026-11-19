<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Column;

/**
 * One project of the index as its row shows it, every cell already in words; the
 * client's address is null for internal work, which has no client.
 */
final readonly class ProjectIndexRow
{
    public function __construct(
        public string $name,
        public ?string $client,
        public ?string $clientHref,
        public int $openTasks,
        public ?string $due,
    ) {
    }
}
