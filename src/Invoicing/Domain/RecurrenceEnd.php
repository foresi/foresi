<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * When a recurring invoice stops: never, after a date (that day included) or
 * after a number of issued invoices.
 */
enum RecurrenceEnd: string
{
    case Never = 'never';
    case OnDate = 'on_date';
    case AfterCount = 'after_count';
}
