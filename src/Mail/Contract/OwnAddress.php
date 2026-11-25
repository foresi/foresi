<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Contract;

/**
 * An inbound address of the signed-in person as a list shows it: the address
 * with its token cut out, never the token.
 */
final readonly class OwnAddress
{
    public function __construct(
        public string $id,
        public string $purpose,
        public string $label,
        public string $maskedAddress,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $lastReceivedAt,
        /** How many e-mails the daily limit dropped today; 0 when it was not reached today. */
        public int $droppedToday,
    ) {
    }

    public function limitReachedToday(): bool
    {
        return $this->droppedToday > 0;
    }
}
