<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Role;

/**
 * Answers what a user is in the instance: one membership per person (ADR 0035).
 */
interface MembershipDirectory
{
    /**
     * The role of the user's active membership; null without one, or when it
     * is deactivated.
     */
    public function roleOf(string $userId): ?Role;

    /**
     * The organisation with its name and the role the user holds there, for
     * the shell and the mails that name it: null without an active membership.
     */
    public function organisationOf(string $userId): ?Organisation;
}
