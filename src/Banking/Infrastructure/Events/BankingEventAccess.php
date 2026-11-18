<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\Events;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\StaffCheck;
use Foresi\Shared\Application\Event\EventReadAccess;

/**
 * The events of the bank tell about the money of the firms, so they are read
 * by those who work with the bank: the owners and members of the organisation.
 */
final readonly class BankingEventAccess implements EventReadAccess
{
    public function __construct(private AuditActor $actor, private StaffCheck $staff)
    {
    }

    public function module(): string
    {
        return 'banking';
    }

    public function mayRead(): bool
    {
        return null !== $this->actor->userId() && $this->staff->isStaff();
    }

    public function mayUserRead(string $userId): bool
    {
        return $this->staff->isStaffMember($userId);
    }
}
