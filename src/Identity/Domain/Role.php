<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * What a user is within one tenant: the owner, a member of the staff, or an
 * external person the organisation shares work with. What an external person
 * is (a client, a supplier, a subcontractor) comes from the contact their
 * membership links, not from the role. Fine-grained permissions come later.
 */
enum Role: string
{
    case Owner = 'owner';
    case Member = 'member';
    case External = 'external';

    /**
     * The owner and the members work for the organisation and see its modules;
     * an external person sees only what is shared with them.
     */
    public function isStaff(): bool
    {
        return self::External !== $this;
    }
}
