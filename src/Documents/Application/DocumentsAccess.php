<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\StaffCheck;

/**
 * Documents are for the owners and members of the organisation only; guests
 * are refused everywhere (ADR 0041).
 */
final readonly class DocumentsAccess
{
    public function __construct(private StaffCheck $staff, private AuditActor $actor)
    {
    }

    /**
     * @return string the id of the person acting
     *
     * @throws DocumentsRefused forbidden
     */
    public function denyUnlessStaff(): string
    {
        $userId = $this->actor->userId();
        if (null === $userId || !$this->staff->isStaff()) {
            throw DocumentsRefused::forbidden();
        }

        return $userId;
    }

    public function isStaff(): bool
    {
        // StaffCheck refuses who is signed out.
        return $this->staff->isStaff();
    }
}
