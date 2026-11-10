<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application\Query;

use App\Party\Application\PartyAccess;
use App\Party\Application\PartyDetails;
use App\Party\Application\PartyNotAllowed;
use App\Party\Application\PartyQuery;
use App\Party\Application\UnknownParty;
use App\Party\Domain\ContactLink;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyBankAccount;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\RegistryResult;
use App\Shared\Domain\Id;

/**
 * One contact with its addresses, e-mails, bank accounts and contact persons,
 * for the API and MCP. Only the owners and members of the organisation read it.
 */
final readonly class ContactDetails
{
    public function __construct(
        private PartyAccess $access,
        private PartyQuery $parties,
        private PartyDetails $details,
    ) {
    }

    /**
     * @throws PartyNotAllowed for anyone but the owners and members of the organisation
     * @throws UnknownParty    also for a contact of another tenant
     */
    public function of(string $id): ContactDetail
    {
        $party = $this->party($id);

        return new ContactDetail(
            ContactRow::of($party, $this->details->billingAddress($party)?->address(), $this->details->generalEmail($party)?->address()),
            $party->phone(),
            $party->mobile(),
            $party->website(),
            RegistryResult::Unreliable === $party->unreliablePayerCheck()?->result,
            array_map(static fn (PartyAddress $address): ContactAddress => new ContactAddress($address->id(), $address->type(), $address->label(), $address->address()), $this->details->addresses($party)),
            array_map(static fn (PartyEmail $email): ContactEmail => new ContactEmail($email->id(), $email->address(), $email->purpose()), $this->details->emails($party)),
            array_map(static fn (PartyBankAccount $account): ContactBankAccount => new ContactBankAccount($account->id(), $account->iban()->toString(), $account->bic(), $account->label(), $account->isPrimary()), $this->details->bankAccounts($party)),
            array_map(static fn (ContactLink $link): ContactPersonLink => new ContactPersonLink($link->id(), $link->company()->id(), $link->company()->displayName(), $link->person()->id(), $link->person()->displayName(), $link->position(), $link->isPrimary()), $this->details->contactLinks($party)),
        );
    }

    /**
     * The contact itself, for the screen that reads the rest of it as it shows it.
     *
     * @throws PartyNotAllowed for anyone but the owners and members of the organisation
     * @throws UnknownParty    also for a contact of another tenant
     */
    public function party(string $id): Party
    {
        $this->access->denyUnlessStaff();

        return Id::isValid($id) ? ($this->parties->find($id) ?? throw new UnknownParty()) : throw new UnknownParty();
    }
}
