<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\StaffCheck;
use Foresi\Margin\Domain\MarginRefused;

/**
 * Who works with the margin: the owners and the members of the organisation,
 * never its guests, on screens and with a token alike, since the margin is
 * the firm's and not a person's. Every use case asks here first.
 */
final readonly class MarginAccess
{
    public function __construct(private StaffCheck $staff, private AuditActor $actor)
    {
    }

    /**
     * @throws MarginRefused forbidden
     */
    public function denyUnlessStaff(): void
    {
        if (null === $this->actor->userId() || !$this->staff->isStaff()) {
            throw MarginRefused::forbidden();
        }
    }
}
