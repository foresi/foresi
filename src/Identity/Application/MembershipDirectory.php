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
     * The user's organisation with its name and the role held there, for mails
     * that name it: empty without an active membership. It goes with the
     * tenant itself.
     *
     * @return list<Organisation>
     */
    public function organisationsOf(string $userId): array;
}
