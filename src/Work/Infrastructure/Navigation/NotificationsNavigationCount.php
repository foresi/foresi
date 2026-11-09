<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Navigation;

use App\Audit\Application\AuditActor;
use App\Identity\Application\People;
use App\Identity\Domain\Membership;
use App\Ui\Navigation\NavigationCountProvider;
use App\Work\Application\NotificationQueries;

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
