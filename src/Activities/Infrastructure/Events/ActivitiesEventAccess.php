<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Infrastructure\Events;

use Foresi\Activities\Application\ActivitiesAccess;
use Foresi\Shared\Application\Event\EventReadAccess;

/**
 * The events of Activities are for the owners and members, as the activities are.
 */
final readonly class ActivitiesEventAccess implements EventReadAccess
{
    public function __construct(private ActivitiesAccess $access)
    {
    }

    public function module(): string
    {
        return 'activities';
    }

    public function mayRead(): bool
    {
        return $this->access->isStaff();
    }

    public function mayUserRead(string $userId): bool
    {
        return $this->access->isStaffMember($userId);
    }
}
