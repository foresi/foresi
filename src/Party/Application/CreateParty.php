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
 * address, and with a general e-mail address when one is given. It owns its
 * unit of work: everything is stored in one transaction, so a failure leaves
 * nothing behind.
 */
final readonly class CreateParty
{
    public function __construct(private Parties $parties, private TransactionalWork $transaction)
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
        return $this->createFrom(new PartyInput($kind, $displayName, $registrationNumber, $vatId, $email, $phone, $roles, $billingAddress));
    }

    public function createFrom(PartyInput $input): Party
    {
        return $this->transaction->run(function () use ($input): Party {
            $party = new Party($input->kind, $input->displayName, $input->registrationNumber, $input->vatId, $input->phone, $input->roles);
            $party->changePrivateNote($input->privateNote);
            $emails = '' === trim($input->email) ? [] : [new PartyEmail($party, $input->email, EmailPurpose::General)];
            $this->parties->register($party, new PartyAddress($party, AddressType::Billing, $input->billingAddress), $emails);

            return $party;
        });
    }
}
