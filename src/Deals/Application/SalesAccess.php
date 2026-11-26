<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Contract\StaffCheck;

/**
 * Who works with deals: the owners and members of the organisation, never a
 * guest (ADR 0041).
 */
final readonly class SalesAccess
{
    public function __construct(private StaffCheck $staff, private AuditActor $actor)
    {
    }

    /**
     * @return string the id of the person acting
     *
     * @throws SalesRefused forbidden
     */
    public function denyUnlessStaff(): string
    {
        $userId = $this->actor->userId();
        if (null === $userId || !$this->staff->isStaff()) {
            throw SalesRefused::forbidden();
        }

        return $userId;
    }

    public function mayWork(): bool
    {
        return null !== $this->actor->userId() && $this->staff->isStaff();
    }

    /** Whether the user is an owner or member, who may own deals. */
    public function isStaffMember(string $userId): bool
    {
        return $this->staff->isStaffMember($userId);
    }
}
