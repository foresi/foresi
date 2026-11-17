<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Domain\Schedule;

/**
 * When a recurrence stops: never, after a date (that day included) or
 * after a number of occurrences (issued invoices, for example).
 */
enum RecurrenceEnd: string
{
    case Never = 'never';
    case OnDate = 'on_date';
    case AfterCount = 'after_count';
}
