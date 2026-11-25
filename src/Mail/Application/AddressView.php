<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

use Foresi\Mail\Domain\InboundAddress;

/**
 * One of a person's inbound addresses as a list shows it: never the token, and
 * only the template of the address, with the token left out.
 */
final readonly class AddressView
{
    public function __construct(
        public string $id,
        public string $purpose,
        public string $label,
        public string $maskedAddress,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $revokedAt,
        public ?\DateTimeImmutable $lastReceivedAt,
    ) {
    }

    public static function of(InboundAddress $address, string $maskedAddress): self
    {
        return new self($address->id(), $address->purpose(), $address->label(), $maskedAddress, $address->createdAt(), $address->revokedAt(), $address->lastReceivedAt());
    }
}
