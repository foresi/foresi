<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Integration\Contacts;

use Foresi\Contacts\Contract\ContactCreation;
use Foresi\Contacts\Contract\ContactsByEmail;
use Foresi\Contacts\Contract\NewContact;
use Foresi\Inbox\Application\InboxAddressBook;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The inbox matches senders to the address book and creates contacts in it.
 */
#[AsAlias(InboxAddressBook::class)]
final readonly class ContactsInboxAddressBook implements InboxAddressBook
{
    public function __construct(private ContactsByEmail $contacts, private ContactCreation $creation)
    {
    }

    public function byAddress(string $email): array
    {
        return $this->contacts->byAddress($email);
    }

    public function byDomain(string $domain): array
    {
        return $this->contacts->byDomain($domain);
    }

    public function companiesOfPerson(string $personId): array
    {
        return $this->contacts->companiesOfPerson($personId);
    }

    public function create(string $kind, string $displayName, string $street, string $city, string $postalCode, string $countryCode, string $registrationNumber, string $vatId, string $email, string $phone): string
    {
        return $this->creation->create(new NewContact($kind, $displayName, $street, $city, $postalCode, $countryCode, $registrationNumber, $vatId, $email, $phone));
    }
}
