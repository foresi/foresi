<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;

/**
 * The one way to create a party: it always comes with exactly one billing
 * address, and with a general e-mail address when one is given. The caller
 * flushes.
 */
final readonly class CreateParty
{
    public function __construct(private Parties $parties)
    {
    }

    /**
     * @param list<PartyRole> $roles
     */
    public function create(
        PartyKind $kind,
        string $displayName,
        ?RegistrationNumber $registrationNumber,
        ?VatId $vatId,
        string $email,
        string $phone,
        array $roles,
        Address $billingAddress,
    ): Party {
        $party = new Party($kind, $displayName, $registrationNumber, $vatId, $phone, $roles);
        $emails = '' === trim($email) ? [] : [new PartyEmail($party, $email, EmailPurpose::General)];
        $this->parties->register($party, new PartyAddress($party, AddressType::Billing, $billingAddress), $emails);

        return $party;
    }
}
