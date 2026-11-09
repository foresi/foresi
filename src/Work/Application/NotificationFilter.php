<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * The tabs of the inbox: everything, or only the rows a mention or an
 * assignment folded into (Linear, ClickUp).
 */
enum NotificationFilter: string
{
    case All = 'all';
    case Mentions = 'mentions';
    case Assigned = 'assigned';
}
