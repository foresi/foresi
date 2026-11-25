<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

/**
 * An address just issued, with its full text, which exists only in this answer.
 */
final readonly class IssuedMailAddress
{
    public function __construct(
        public AddressView $view,
        #[\SensitiveParameter]
        public string $address,
    ) {
    }
}
