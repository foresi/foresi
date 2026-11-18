<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Contract\Event\ContactKind;
use Foresi\Party\Contract\Event\ContactUpdated;
use Foresi\Party\Domain\EmailPurpose;
use Foresi\Party\Domain\InvoicingDefaults;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyEmail;
use Foresi\Shared\Application\Event\EventRecorder;

/**
 * Changes what the address book form edits: the name, identifiers, phones,
 * website, roles, note, billing address and general e-mail, and the document
 * defaults when given. The kind of a party does
 * not change. One unit of work: everything is stored or nothing is, with
 * party.contact.updated. Only the owners and members of the organisation
 * change a contact, or the application's own work.
 */
final readonly class UpdateParty
{
    public function __construct(
        private Parties $parties,
        private PartyDetails $details,
        private TransactionalWork $transaction,
        private PartyAccess $access,
        private EventRecorder $events,
    ) {
    }

    /**
     * @throws PartyNotAllowed for a guest
     */
    public function update(Party $party, PartyInput $input): void
    {
        $this->access->denyUnlessMayChange();
        $this->transaction->run(function () use ($party, $input): void {
            $party->rename($input->displayName);
            $party->changePhone($input->phone);
            $party->changeIdentifiers($input->registrationNumber, $input->vatId);
            $party->assignRoles($input->roles);
            $party->changePrivateNote($input->privateNote);
            $party->changeMobile($input->mobile);
            $party->changeWebsite($input->website);
            if ($input->invoicingDefaults instanceof InvoicingDefaults) {
                $party->changeInvoicingDefaults($input->invoicingDefaults);
            }
            ($this->details->billingAddress($party) ?? throw new \DomainException('The party has no billing address to change.'))->relocate($input->billingAddress);
            $this->changeEmail($party, trim($input->email));
            $this->events->record(new ContactUpdated($party->id(), ContactKind::from($party->kind()->value)));
        });
    }

    private function changeEmail(Party $party, string $address): void
    {
        $email = $this->details->generalEmail($party);
        if ('' === $address) {
            if ($email instanceof PartyEmail) {
                $this->parties->removeEmail($email);
            }

            return;
        }

        if ($email instanceof PartyEmail) {
            $email->change($address, EmailPurpose::General);
        } else {
            $this->parties->addEmail(new PartyEmail($party, $address, EmailPurpose::General));
        }
    }
}
