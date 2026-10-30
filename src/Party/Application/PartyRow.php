<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;

/**
 * One line of the address book list.
 */
final readonly class PartyRow
{
    /**
     * @param list<PartyRole> $roles
     */
    public function __construct(
        public int $id,
        public string $displayName,
        public PartyKind $kind,
        public array $roles,
        public ?string $registrationNumber,
        public ?string $vatId,
        public ?string $email,
        public string $phone,
        public bool $archived,
        public bool $unreliablePayer,
    ) {
    }
}
