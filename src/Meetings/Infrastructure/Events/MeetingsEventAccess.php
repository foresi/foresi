<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Events;

use Foresi\Identity\Contract\StaffCheck;
use Foresi\Meetings\Application\MeetingVisibility;
use Foresi\Shared\Application\Event\EventReadAccess;

/**
 * Who reads the events of Meetings: the owners and members, never a guest. The
 * payloads are thin, ids only, so they tell nothing of a meeting the reader does
 * not see; the meeting itself is read through its visibility.
 */
final readonly class MeetingsEventAccess implements EventReadAccess
{
    public function __construct(private MeetingVisibility $visibility, private StaffCheck $staff)
    {
    }

    public function module(): string
    {
        return 'meetings';
    }

    public function mayRead(): bool
    {
        return $this->visibility->mayUse();
    }

    public function mayUserRead(string $userId): bool
    {
        return $this->staff->isStaffMember($userId);
    }
}
