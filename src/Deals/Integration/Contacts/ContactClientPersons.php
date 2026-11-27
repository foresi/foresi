<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Integration\Contacts;

use Foresi\Contacts\Contract\ContactPersonRefused;
use Foresi\Contacts\Contract\ContactPersonWrites;
use Foresi\Deals\Application\ClientPersonRefused;
use Foresi\Deals\Application\ClientPersons;
use Foresi\Party\Contract\PartyPersonSummary;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * A deal's client takes its contact persons from the address book.
 */
#[AsAlias(ClientPersons::class)]
final readonly class ContactClientPersons implements ClientPersons
{
    public function __construct(private ContactPersonWrites $writes)
    {
    }

    public function candidatesOf(string $clientId): array
    {
        return $this->writes->candidatesOf($clientId);
    }

    public function link(string $clientId, string $personId, string $position): PartyPersonSummary
    {
        try {
            return $this->writes->link($clientId, $personId, $position);
        } catch (ContactPersonRefused $refused) {
            throw new ClientPersonRefused($refused->reasons);
        }
    }

    public function addNew(string $clientId, string $name, string $email, string $position): PartyPersonSummary
    {
        try {
            return $this->writes->addNew($clientId, $name, $email, $position);
        } catch (ContactPersonRefused $refused) {
            throw new ClientPersonRefused($refused->reasons);
        }
    }
}
