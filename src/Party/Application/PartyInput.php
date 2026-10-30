<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\Address;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;

/**
 * Everything the address book form collects about a party, already parsed into
 * domain values. The e-mail is the general one; an empty string means none.
 */
final readonly class PartyInput
{
    /**
     * @param list<PartyRole> $roles
     */
    public function __construct(
        public PartyKind $kind,
        public string $displayName,
        public ?RegistrationNumber $registrationNumber,
        public ?VatId $vatId,
        public string $email,
        public string $phone,
        public array $roles,
        public Address $billingAddress,
        public string $privateNote = '',
    ) {
    }
}
