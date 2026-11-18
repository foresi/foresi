<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Domain\Schedule;

/**
 * The step a recurrence repeats by. Yearly and "every 12 months" give the
 * same dates but stay distinct, as people chose one or the other.
 */
enum RecurrenceUnit: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';
}
