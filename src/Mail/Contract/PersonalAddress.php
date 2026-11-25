<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Contract;

/**
 * One of a person's own addresses as a list shows it: never the token, only
 * the template of the address with the token left out.
 */
final readonly class PersonalAddress
{
    public function __construct(
        public string $id,
        public string $purpose,
        public string $label,
        public string $maskedAddress,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $revokedAt,
        /** How many e-mails the daily limit dropped today; 0 when it was not reached today. */
        public int $droppedToday,
    ) {
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt instanceof \DateTimeImmutable;
    }
}
