<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;

/**
 * One line of the address book list. The contact it was read from and its billing address give
 * the attributes a person adds as columns, without another query.
 */
final readonly class PartyRow
{
    /**
     * @param list<PartyRole> $roles
     */
    public function __construct(
        public string $id,
        public string $displayName,
        public PartyKind $kind,
        public array $roles,
        public ?string $registrationNumber,
        public ?string $vatId,
        public ?string $email,
        public string $phone,
        public bool $archived,
        public bool $unreliablePayer,
        public ?Party $party = null,
        public ?Address $billing = null,
    ) {
    }
}
