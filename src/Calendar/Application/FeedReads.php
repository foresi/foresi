<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Calendar\Domain\CalendarFeed;
use Foresi\Identity\Application\StaffCheck;

/**
 * The person's own calendar feeds, never their addresses.
 */
final readonly class FeedReads
{
    public function __construct(
        private CalendarStore $store,
        private StaffCheck $staff,
        private AuditActor $actor,
    ) {
    }

    /**
     * @return list<CalendarFeed> oldest first, revoked ones too
     *
     * @throws CalendarRefused
     */
    public function mine(): array
    {
        $userId = $this->actor->userId();
        if (null === $userId || !$this->staff->isStaff()) {
            throw CalendarRefused::forbidden();
        }

        return $this->store->feedsOf($userId);
    }
}
