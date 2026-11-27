<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Party\Contract\PartySummary;
use Foresi\Shared\Contract\Refused;

/**
 * Port: the address book as the inbox uses it, to match a sender and to create a contact from a
 * message. The address book answers it (Integration/Contacts); without it no sender is matched
 * by address or domain, and no contact is created from a message. It reads with nobody signed in,
 * as mail is received from the webhook or the mailbox poller.
 */
interface InboxAddressBook
{
    /**
     * @return list<PartySummary> the contacts, archived ones left out, with this address among their e-mails
     */
    public function byAddress(string $email): array;

    /**
     * @return list<PartySummary> the companies and sole traders, archived ones left out, with an e-mail of exactly this domain
     */
    public function byDomain(string $domain): array;

    /**
     * @return list<PartySummary> the companies and sole traders a person is linked to, an archived one too
     */
    public function companiesOfPerson(string $personId): array;

    /**
     * A new contact, under the address book's own rules, as the person signed in.
     *
     * @param string $kind person, sole_trader or company
     *
     * @return string the id of the new contact
     *
     * @throws Refused the address book's own refusal, unchanged
     */
    public function create(string $kind, string $displayName, string $street, string $city, string $postalCode, string $countryCode, string $registrationNumber, string $vatId, string $email, string $phone): string;
}
