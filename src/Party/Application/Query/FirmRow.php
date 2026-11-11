<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application\Query;

/**
 * One firm of the organisation as a list shows it.
 */
final readonly class FirmRow
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $archived,
    ) {
    }
}
