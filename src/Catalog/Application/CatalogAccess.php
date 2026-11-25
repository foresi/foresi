<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Contract\StaffCheck;

/**
 * Who works with the catalog: the owners and members of the organisation,
 * never a guest.
 */
final readonly class CatalogAccess
{
    public function __construct(private StaffCheck $staff, private AuditActor $actor)
    {
    }

    /**
     * @throws CatalogRefused forbidden
     */
    public function denyUnlessStaff(): void
    {
        if (null === $this->actor->userId() || !$this->staff->isStaff()) {
            throw CatalogRefused::forbidden();
        }
    }

    public function mayWork(): bool
    {
        return null !== $this->actor->userId() && $this->staff->isStaff();
    }
}
