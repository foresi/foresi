<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Contacts\Infrastructure\Events;

use Foresi\Party\Application\PartyAccess;
use Foresi\Party\Application\PartyNotAllowed;
use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\Event\EventReadAccess;

/**
 * The events of the address book tell about contacts, so they are read by those
 * who read the address book: the owners and members of the organisation.
 */
final readonly class ContactsEventAccess implements EventReadAccess
{
    public function __construct(private PartyAccess $access, private ActorRole $role)
    {
    }

    public function module(): string
    {
        return 'contacts';
    }

    public function mayRead(): bool
    {
        try {
            $this->access->denyUnlessStaff();
        } catch (PartyNotAllowed) {
            return false;
        }

        return true;
    }

    public function mayUserRead(string $userId): bool
    {
        return $this->role->isStaffMember($userId);
    }
}
