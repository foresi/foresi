<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Mail;

/**
 * A capture address just created or replaced, with the address to forward to.
 * Never log it.
 */
final readonly class IssuedCaptureAddress
{
    public function __construct(
        public string $id,
        public string $label,
        #[\SensitiveParameter]
        public string $address,
    ) {
    }
}
