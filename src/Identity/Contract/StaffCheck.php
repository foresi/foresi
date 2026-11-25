<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Contract;

/**
 * Whether a person works for the active organisation, as its owner or a
 * member; guests do not. A module shows its staff screens, navigation and
 * tools to the staff only through it, as roles are membership data the
 * firewall does not know.
 */
interface StaffCheck
{
    /**
     * Whether the signed-in user is staff of the active organisation.
     */
    public function isStaff(): bool;

    /**
     * Whether a given user, signed in or not, still works for the active
     * organisation as its owner or a member: for work done on their behalf,
     * such as a copy of a mail a run sends for them.
     */
    public function isStaffMember(string $userId): bool;
}
