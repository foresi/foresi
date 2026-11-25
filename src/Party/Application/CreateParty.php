<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Contract\Event\ContactCreated;
use Foresi\Party\Contract\Event\ContactKind;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\EmailPurpose;
use Foresi\Party\Domain\InvoicingDefaults;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyEmail;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Shared\Contract\Event\EventRecorder;

/**
 * The one way to create a party: it always comes with exactly one billing
 * address, and with a general e-mail address when one is given. It owns its
 * unit of work: everything is stored in one transaction, so a failure leaves
 * nothing behind, and records party.contact.created with it. Only the owners
 * and members of the organisation create, or the application's own work.
 */
final readonly class CreateParty
{
    public function __construct(
        private Parties $parties,
        private TransactionalWork $transaction,
        private PartyAccess $access,
        private EventRecorder $events,
    ) {
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

    /**
     * @throws PartyNotAllowed for a guest
     */
    public function createFrom(PartyInput $input): Party
    {
        $this->access->denyUnlessMayChange();

        return $this->transaction->run(function () use ($input): Party {
            $party = new Party($input->kind, $input->displayName, $input->registrationNumber, $input->vatId, $input->phone, $input->roles);
            $party->changePrivateNote($input->privateNote);
            $party->changeMobile($input->mobile);
            $party->changeWebsite($input->website);
            if ($input->invoicingDefaults instanceof InvoicingDefaults) {
                $party->changeInvoicingDefaults($input->invoicingDefaults);
            }
            $emails = '' === trim($input->email) ? [] : [new PartyEmail($party, $input->email, EmailPurpose::General)];
            $this->parties->register($party, new PartyAddress($party, AddressType::Billing, $input->billingAddress), $emails);
            $this->events->record(new ContactCreated($party->id(), ContactKind::from($party->kind()->value)));

            return $party;
        });
    }
}
