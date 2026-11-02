<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\RecurringState;
use App\Tenancy\Application\Schedule\ScheduledJobRuns;

/**
 * A template saved or resumed with a date due today, or earlier, waits for the
 * daily run only while that run is still to come today. Once the day's run has
 * finished, the template is handed to the worker at once (RecurringIssueQueue),
 * which issues and sends it through the same path as the run, so the first
 * invoice leaves within minutes. Either way each date is issued once: the
 * occurrence key sees to that.
 */
final readonly class IssueAfterTodaysRun
{
    public function __construct(
        private RecurringInvoices $templates,
        private ScheduledJobRuns $runs,
        private IssueRecurringInvoices $run,
        private RecurringIssueQueue $queue,
    ) {
    }

    /**
     * Whether the recurring run of the tenant's today has finished, so a date due
     * today is no longer the run's to issue.
     */
    public function todaysRunFinished(): bool
    {
        $last = $this->runs->lastFinished(IssueRecurringInvoices::NAME);

        return null !== $last && $last >= $this->run->today()->format('Y-m-d');
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
        if ($next->format('Y-m-d') > $this->run->today()->format('Y-m-d') || !$this->todaysRunFinished()) {
            return false;
        }
        $this->queue->issue($templateId);

        return true;
    }
}
