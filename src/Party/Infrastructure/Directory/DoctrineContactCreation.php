<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Directory;

use Foresi\Party\Application\Contact\ContactWrites;
use Foresi\Party\Application\Contact\CreateContactInput;
use Foresi\Party\Contract\ContactCreation;
use Foresi\Party\Contract\NewContact;

/**
 * The contract over the address book's own create (ContactWrites), so its rules, access check,
 * audit, events and duplicate refusal stay in one place; its refusals pass through unchanged.
 */
final readonly class DoctrineContactCreation implements ContactCreation
{
    public function __construct(private ContactWrites $writes)
    {
    }

    public function create(NewContact $contact): string
    {
        return $this->writes->create(new CreateContactInput(
            $contact->kind,
            $contact->displayName,
            $contact->street,
            $contact->city,
            $contact->postalCode,
            $contact->countryCode,
            registrationNumber: $contact->registrationNumber,
            vatId: $contact->vatId,
            email: $contact->email,
            phone: $contact->phone,
        ));
    }
}
