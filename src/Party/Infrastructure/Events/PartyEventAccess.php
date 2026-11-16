<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Events;

use App\Identity\Application\StaffCheck;
use App\Party\Application\PartyAccess;
use App\Party\Application\PartyNotAllowed;
use App\Shared\Application\Event\EventReadAccess;

/**
 * The events of the address book tell about contacts, so they are read by those
 * who read the address book: the owners and members of the organisation.
 */
final readonly class PartyEventAccess implements EventReadAccess
{
    public function __construct(private PartyAccess $access, private StaffCheck $staff)
    {
    }

    public function module(): string
    {
        return 'party';
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
        return $this->staff->isStaffMember($userId);
    }
}
