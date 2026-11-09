<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Domain;

/**
 * What happened on a task that a person hears about: the core events of
 * Asana, ClickUp, Basecamp, Linear and GitHub.
 */
enum NotificationKind: string
{
    /** A comment mentions the person. */
    case Mentioned = 'mentioned';
    /** The person became responsible for the task. */
    case Assigned = 'assigned';
    /** Someone commented on a task the person watches. */
    case Commented = 'commented';
    /** The status of a task the person watches changed. */
    case StatusChanged = 'status_changed';
    /** The task was shared with the person. */
    case Shared = 'shared';

    /**
     * Whether the event is addressed to the person themselves rather than to
     * everyone following the task: what is worth an e-mail at once.
     */
    public function isDirect(): bool
    {
        return self::Mentioned === $this || self::Assigned === $this;
    }
}
