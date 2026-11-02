<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\PeriodText;
use App\Invoicing\Domain\Recurrence;
use App\Invoicing\Domain\RecurrenceEnd;
use App\Invoicing\Domain\RecurringSchedule;

/**
 * The next invoices a template would issue, as the form shows them before a save.
 * Each is issued on its occurrence date when the run is on time, so that date is
 * the issue date here; the tax point and the due date follow from it as on the
 * invoice. The end rule counts issued invoices, not the dates passed, as the
 * template does. It reads nothing and writes nothing.
 */
final readonly class RecurringPreview
{
    public const int COUNT = 5;

    /**
     * @param int                $issued how many invoices the template has issued so far
     * @param \DateTimeImmutable $today  the tenant's today: no date before it is shown
     *
     * @return list<PreviewRow>
     */
    public function next(RecurringInput $input, int $issued, \DateTimeImmutable $today, int $count = self::COUNT): array
    {
        $recurrence = $input->recurrence;
        $open = new Recurrence($recurrence->firstIssueOn(), $recurrence->unit(), $recurrence->every(), $recurrence->onLastDayOfMonth(), RecurrenceEnd::Never, null, null);
        $rows = [];
        foreach (RecurringSchedule::next($open, 0, $today, $count) as $index => $date) {
            if (RecurringSchedule::isOver($recurrence, $issued + $index, $date)) {
                break;
            }
            $rows[] = new PreviewRow(
                $date,
                $input->taxPoint->for($date),
                RecurringSchedule::calendarDay($date->getTimezone(), (int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j') + $input->dueDays),
                PeriodText::resolve($input->description, $date),
            );
        }

        return $rows;
    }
}
