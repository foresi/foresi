<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Infrastructure\Events;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Shared\Application\Event\EventReadAccess;

/**
 * The events of inbound mail are read by the owners and members of the
 * organisation; which of them sees a given message is MailEventSubjectAccess.
 */
final readonly class MailEventAccess implements EventReadAccess
{
    public function __construct(private AuditActor $actor, private StaffCheck $staff)
    {
    }

    public function module(): string
    {
        return 'mail';
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
