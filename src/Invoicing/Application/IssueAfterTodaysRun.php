<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\RecurringState;

/**
 * A template saved or resumed with a date due today, or earlier, waits for the
 * daily run only while the run's time is still to come today (before
 * IssueRecurringInvoices::START_HOUR in the tenant's zone). Once that time has
 * passed, the template is handed to the worker at once (RecurringIssueQueue),
 * whether or not the run has finished: a run that is late, or a scheduler that
 * is not running, must not leave a promised invoice waiting for tomorrow. The
 * worker issues and sends it through the same path as the run. Either way each
 * date is issued once: the occurrence key sees to that.
 */
final readonly class IssueAfterTodaysRun
{
    public function __construct(
        private RecurringInvoices $templates,
        private IssueRecurringInvoices $run,
        private RecurringIssueQueue $queue,
    ) {
    }

    /**
     * Whether a save now issues this date at once: it is due by the run's day,
     * which begins at the run's hour, so the run of today has had its time.
     * Before that hour the date of today is the run's to issue.
     */
    public function issuesOnSave(\DateTimeImmutable $date): bool
    {
        return $date->format('Y-m-d') <= $this->run->today()->format('Y-m-d');
    }

    /**
     * Call after the template is committed. It starts the session over.
     *
     * @return bool whether the template was handed to the worker
     *
     * @throws UnknownRecurringInvoice also for a template of another tenant
     */
    public function afterChange(string $templateId): bool
    {
        $template = $this->templates->current($templateId);
        $next = $template->nextOccurrence();
        if (RecurringState::Active !== $template->state() || !$next instanceof \DateTimeImmutable) {
            return false;
        }
        if (!$this->issuesOnSave($next)) {
            return false;
        }
        $this->queue->issue($templateId);

        return true;
    }
}
