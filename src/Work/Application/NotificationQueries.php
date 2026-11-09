<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Identity\Domain\Membership;

/**
 * The inbox of a person. Only rows of tasks the person sees now count: a task
 * taken away from them, made private or put in the trash leaves their inbox
 * at once, and comes back with its rows when they see it again.
 */
interface NotificationQueries
{
    public const int PAGE_SIZE = 50;

    /**
     * The rows not read and not done.
     */
    public function unreadCount(Membership $recipient): int;

    /**
     * The rows not done, or the done ones, newest first.
     *
     * @param int $page from 1
     */
    public function inbox(Membership $recipient, NotificationFilter $filter = NotificationFilter::All, bool $done = false, int $page = 1): NotificationPage;
}
