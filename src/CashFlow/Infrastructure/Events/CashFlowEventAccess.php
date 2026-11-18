<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Events;

use Foresi\CashFlow\Application\CashFlowAccess;
use Foresi\CashFlow\Application\CashFlowRefused;
use Foresi\Identity\Application\StaffCheck;
use Foresi\Shared\Application\Event\EventReadAccess;

/**
 * The events of the cash flow tell about planned items, so they are read by
 * those who work with the cash flow: the owners and members.
 */
final readonly class CashFlowEventAccess implements EventReadAccess
{
    public function __construct(private CashFlowAccess $access, private StaffCheck $staff)
    {
    }

    public function module(): string
    {
        return 'cash-flow';
    }

    public function mayRead(): bool
    {
        try {
            $this->access->denyUnlessStaff();
        } catch (CashFlowRefused) {
            return false;
        }

        return true;
    }

    public function mayUserRead(string $userId): bool
    {
        return $this->staff->isStaffMember($userId);
    }
}
