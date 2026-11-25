<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Events;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Shared\Application\Event\EventReadAccess;

/**
 * The events of Inbox are read by the owners and members of the organisation,
 * never by guests; which items each of them sees is InboxEventSubjectAccess.
 */
final readonly class InboxEventAccess implements EventReadAccess
{
    public function __construct(private AuditActor $actor, private StaffCheck $staff)
    {
    }

    public function module(): string
    {
        return 'inbox';
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
