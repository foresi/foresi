<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Contract;

/**
 * A person's address just created or replaced, with its full text, which
 * exists only in this answer.
 */
final readonly class IssuedPersonalAddress
{
    public function __construct(
        public PersonalAddress $view,
        #[\SensitiveParameter]
        public string $address,
    ) {
    }
}
