<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application\Query;

use App\Party\Domain\Address;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;

/**
 * One contact as a list shows it.
 */
final readonly class ContactRow
{
    /**
     * @param list<PartyRole> $roles
     * @param string|null     $email the general e-mail address; none when the contact has no address of that purpose
     */
    public function __construct(
        public string $id,
        public PartyKind $kind,
        public string $name,
        public array $roles,
        public ?string $registrationCountry,
        public ?string $registrationNumber,
        public ?string $vatId,
        public ?string $email,
        public ?Address $billingAddress,
        public bool $archived,
    ) {
    }

    public static function of(Party $party, ?Address $billingAddress, ?string $email): self
    {
        return new self(
            $party->id(),
            $party->kind(),
            $party->displayName(),
            $party->roles(),
            $party->registrationNumber()?->countryCode,
            $party->registrationNumber()?->value,
            $party->vatId()?->toString(),
            $email,
            $billingAddress,
            $party->isArchived(),
        );
    }
}
