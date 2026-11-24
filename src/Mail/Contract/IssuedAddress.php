<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Contract;

/**
 * An address just issued or rotated: its id and the full address, the only
 * time the token leaves Mail for a purpose that does not show it again.
 */
final readonly class IssuedAddress
{
    public function __construct(
        public string $id,
        #[\SensitiveParameter]
        public string $address,
    ) {
    }
}
