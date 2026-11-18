<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Import\Contract;

/** An existing record a source row matches. */
final readonly class RecordMatch
{
    public function __construct(
        public string $id,
        public bool $archived,
    ) {
    }
}
