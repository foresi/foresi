<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Fixtures\PartyConsumer\ReachingIn;

use Foresi\Party\Application\Query\ContactDetails;

/**
 * A module that reads a contact through the address book's own query, past the contract.
 */
final readonly class ContactName
{
    public function __construct(private ContactDetails $contacts)
    {
    }

    public function of(string $id): string
    {
        return $this->contacts->of($id)->contact->name;
    }
}
