<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

/** The contact person an imported row names for its company. */
final readonly class ImportedContact
{
    public function __construct(
        public string $name,
        public string $email,
        public string $phone,
        public string $position,
    ) {
    }
}
