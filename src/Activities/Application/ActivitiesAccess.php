<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\StaffCheck;

/**
 * Activities are for the owners and members of the organisation only; guests
 * are refused everywhere (ADR 0041).
 */
final readonly class ActivitiesAccess
{
    public function __construct(private StaffCheck $staff, private AuditActor $actor)
    {
    }

    /**
     * @return string the id of the person acting
     *
     * @throws ActivitiesRefused forbidden
     */
    public function denyUnlessStaff(): string
    {
        $userId = $this->actor->userId();
        if (null === $userId || !$this->staff->isStaff()) {
            throw ActivitiesRefused::forbidden();
        }

        return $userId;
    }

    public function isStaff(): bool
    {
        // StaffCheck refuses who is signed out.
        return $this->staff->isStaff();
    }

    /** The person acting, or null when signed out. */
    public function userId(): ?string
    {
        return $this->actor->userId();
    }

    /** Whether the user is an owner or member, who may own activities. */
    public function isStaffMember(string $userId): bool
    {
        return $this->staff->isStaffMember($userId);
    }
}
