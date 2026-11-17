<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Events;

use App\Identity\Application\StaffCheck;
use App\Purchasing\Application\PurchasingAccess;
use App\Purchasing\Application\PurchasingNotAllowed;
use App\Shared\Application\Event\EventReadAccess;

/**
 * The events of received documents tell about the costs of the firms, so they
 * are read by those who work with received documents: the owners and members
 * of the organisation.
 */
final readonly class PurchasingEventAccess implements EventReadAccess
{
    public function __construct(private PurchasingAccess $access, private StaffCheck $staff)
    {
    }

    public function module(): string
    {
        return 'purchasing';
    }

    public function mayRead(): bool
    {
        try {
            $this->access->denyUnlessStaff();
        } catch (PurchasingNotAllowed) {
            return false;
        }

        return true;
    }

    public function mayUserRead(string $userId): bool
    {
        return $this->staff->isStaffMember($userId);
    }
}
