<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Navigation;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\People;
use Foresi\Identity\Domain\Membership;
use Foresi\Ui\Navigation\NavigationCountProvider;
use Foresi\Work\Application\NotificationQueries;

/**
 * The rows of my inbox not read yet, in one count query; none shows no number.
 */
final readonly class NotificationsNavigationCount implements NavigationCountProvider
{
    public function __construct(private NotificationQueries $queries, private People $people, private AuditActor $actor)
    {
    }

    public function count(): ?int
    {
        $userId = $this->actor->userId();
        $me = null === $userId ? null : $this->people->membershipOf($userId);
        $count = $me instanceof Membership ? $this->queries->unreadCount($me) : 0;

        return 0 === $count ? null : $count;
    }
}
