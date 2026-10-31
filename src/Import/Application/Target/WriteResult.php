<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application\Target;

/** The record an import wrote and what it looked like before. */
final readonly class WriteResult
{
    public function __construct(
        public int $id,
        public RecordSnapshot $before,
    ) {
    }
}
