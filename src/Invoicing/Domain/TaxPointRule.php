<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * The tax point of an invoice issued from a recurring template, taken from its
 * issue date.
 */
enum TaxPointRule: string
{
    case IssueDate = 'issue_date';
    case LastDayOfMonth = 'last_day_of_month';

    public function for(\DateTimeImmutable $issueDate): \DateTimeImmutable
    {
        $year = (int) $issueDate->format('Y');
        $month = (int) $issueDate->format('n');
        $day = match ($this) {
            self::IssueDate => (int) $issueDate->format('j'),
            self::LastDayOfMonth => RecurringSchedule::monthLength($year, $month),
        };

        return RecurringSchedule::calendarDay($issueDate->getTimezone(), $year, $month, $day);
    }
}
