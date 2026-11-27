<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Infrastructure\Events;

use Foresi\Deals\Application\SalesAccess;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Shared\Application\Event\EventReadAccess;

/**
 * Who reads the events of Sales: the owners and members, as they work with the deals.
 */
final readonly class SalesEventAccess implements EventReadAccess
{
    public function __construct(private SalesAccess $access, private StaffCheck $staff)
    {
    }

    public function module(): string
    {
        return 'deals';
    }

    public function mayRead(): bool
    {
        return $this->access->mayWork();
    }

    public function mayUserRead(string $userId): bool
    {
        return $this->staff->isStaffMember($userId);
    }
}
