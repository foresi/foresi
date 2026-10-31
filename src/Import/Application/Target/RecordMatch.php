<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application\Target;

/** An existing record a source row matches. */
final readonly class RecordMatch
{
    public function __construct(
        public int $id,
        public bool $archived,
    ) {
    }
}
