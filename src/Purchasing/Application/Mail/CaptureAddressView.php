<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Mail;

/**
 * The capture address of the signed-in person, as the settings show it. The
 * address is null when this server cannot open it now (it lost its key or its
 * address template). Never log it.
 */
final readonly class CaptureAddressView
{
    public function __construct(
        public string $id,
        public string $label,
        #[\SensitiveParameter]
        public ?string $address,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $lastReceivedAt,
    ) {
    }
}
