<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application;

/**
 * Port: the role of the current actor in the active organisation. Identity
 * answers it from the memberships, so a core module such as Party checks who
 * may do what without using Identity, which in turn uses Party.
 */
interface ActorRole
{
    /**
     * The id of the signed-in user; null when nobody is signed in, such as in a
     * console command.
     */
    public function userId(): ?string;

    /**
     * Whether the signed-in user owns the organisation.
     */
    public function isOwner(): bool;

    /**
     * Whether the signed-in user works for the organisation, as its owner or a
     * member. Guests do not.
     */
    public function isStaff(): bool;

    /**
     * Whether a given user, signed in or not, still works for the organisation
     * as its owner or a member, for work done on their behalf.
     */
    public function isStaffMember(string $userId): bool;
}
