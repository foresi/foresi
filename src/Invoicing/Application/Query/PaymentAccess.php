<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application\Query;

use App\Audit\Application\AuditActor;
use App\Identity\Application\StaffCheck;

/**
 * Who reads the payments: the owners and the members of the organisation, never
 * its guests. It is the rule of the other reads of invoicing, kept apart until
 * they share one guard.
 */
final readonly class PaymentAccess
{
    public function __construct(private StaffCheck $staff, private AuditActor $actor)
    {
    }

    /**
     * @return string the id of the signed-in person
     *
     * @throws PaymentsNotAllowed
     */
    public function denyUnlessStaff(): string
    {
        $userId = $this->actor->userId();
        if (null === $userId || !$this->staff->isStaff()) {
            throw new PaymentsNotAllowed();
        }

        return $userId;
    }
}
