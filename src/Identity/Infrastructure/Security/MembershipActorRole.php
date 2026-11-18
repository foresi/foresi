<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Security;

use Foresi\Identity\Application\OwnerCheck;
use Foresi\Identity\Application\StaffCheck;
use Foresi\Shared\Application\ActorRole;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The role of the current actor, from the memberships of the organisation.
 */
#[AsAlias(ActorRole::class)]
final readonly class MembershipActorRole implements ActorRole
{
    public function __construct(private OwnerCheck $owner, private StaffCheck $staff)
    {
    }

    public function isOwner(): bool
    {
        return $this->owner->isOwner();
    }

    public function isStaff(): bool
    {
        return $this->staff->isStaff();
    }

    public function isStaffMember(string $userId): bool
    {
        return $this->staff->isStaffMember($userId);
    }
}
