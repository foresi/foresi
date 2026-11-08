<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * When a task is due, as the filters of the list and the board ask it, in the
 * tenant's time zone.
 */
enum DueBucket: string
{
    /** Before today, and not finished. */
    case Overdue = 'overdue';
    case Today = 'today';
    /** Today and the seven days after it. */
    case NextSevenDays = 'next_7_days';
    case None = 'none';
}
