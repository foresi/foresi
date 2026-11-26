<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Contract\Event\ContactCreated;
use Foresi\Party\Contract\Event\ContactKind;
use Foresi\Party\Contract\Field\ContactExtension;
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
use Foresi\Shared\Application\Field\CustomFieldWrites;
use Foresi\Shared\Application\Field\FieldRefused;
use Foresi\Shared\Contract\Event\EventRecorder;

/**
 * The one way to create a party: it always comes with exactly one billing
 * address, and with a general e-mail address when one is given. It owns its
 * unit of work: everything is stored in one transaction, so a failure leaves
 * nothing behind, and records party.contact.created with it. The custom fields
 * the input brings are written in the same transaction, each required one with a
 * value. Only the owners and members of the organisation create, or the
 * application's own work.
 */
final readonly class CreateParty
{
    public function __construct(
        private Parties $parties,
        private TransactionalWork $transaction,
        private PartyAccess $access,
        private EventRecorder $events,
        private CustomFieldWrites $customFields,
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
     * @throws FieldRefused    for a custom field it does not take, or a required one left empty
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
            if (null !== $input->customFields) {
                $this->customFields->write(ContactExtension::RECORD, $party->id(), $input->customFields, true);
            }
            $this->events->record(new ContactCreated($party->id(), ContactKind::from($party->kind()->value)));

            return $party;
        });
    }
}
