<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Application;

use App\Invoicing\Application\BilledWorkRelease;
use App\Invoicing\Application\IssueProblem;
use App\Invoicing\Application\IssueRefused;
use App\Invoicing\Domain\Invoice;
use App\Timesheet\Domain\ReleasedTime;
use App\Work\Application\TimeEntries;
use App\Work\Domain\TimeEntry;
use Psr\Clock\ClockInterface;

/**
 * The time of an invoice in the trash is free again, as in Clockify and Teamwork:
 * unbilled, editable and offered for invoicing. What it was billed as is kept
 * aside, so restoring the invoice bills each entry again on its line, at its rate
 * and for its minutes. Restoring refuses, as for an advance another invoice
 * deducts meanwhile, once an entry was invoiced elsewhere, changed or deleted:
 * the restored invoice would bill time twice or other time than it says. Each
 * change of an entry is audited under its task or project.
 */
final readonly class ReleaseTime implements BilledWorkRelease
{
    public function __construct(
        private TimeEntries $entries,
        private ReleasedTimes $released,
        private ClockInterface $clock,
    ) {
    }

    public function released(Invoice $invoice): void
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        foreach ($this->entries->lockForInvoicing($this->entries->idsInvoicedBy($invoice->id())) as $entry) {
            $line = $entry->invoiceLineId();
            $rate = $entry->invoicedRate();
            $minutes = $entry->invoicedMinutes();
            if (in_array(null, [$line, $rate, $minutes], true)) {
                continue;
            }
            $this->released->add(new ReleasedTime($invoice->id(), $entry->id(), $line, $rate, $minutes, EntryFingerprint::ofEntry($entry), $now));
            $entry->release();
        }
    }

    /**
     * @throws IssueRefused with timesheet.problem.restore_time_invoiced or timesheet.problem.restore_time_changed
     */
    public function reclaim(Invoice $invoice): void
    {
        $released = $this->released->ofInvoice($invoice->id());
        if ([] === $released) {
            return;
        }
        $locked = $this->entries->lockForInvoicing(array_map(static fn (ReleasedTime $time): string => $time->entryId(), $released));
        foreach ($released as $time) {
            $entry = $locked[$time->entryId()] ?? throw $this->refused('timesheet.problem.restore_time_changed');
            if ($entry->isInvoiced()) {
                throw $this->refused('timesheet.problem.restore_time_invoiced');
            }
            if (!$entry->isBillable() || $entry->isRunning() || EntryFingerprint::ofEntry($entry) !== $time->fingerprint()) {
                throw $this->refused('timesheet.problem.restore_time_changed');
            }
            $this->bill($entry, $time);
            $this->released->remove($time);
        }
    }

    private function bill(TimeEntry $entry, ReleasedTime $time): void
    {
        try {
            $entry->invoice($time->invoiceId(), $time->lineId(), $time->rate(), $time->minutes());
        } catch (\DomainException) {
            throw $this->refused('timesheet.problem.restore_time_changed');
        }
    }

    private function refused(string $key): IssueRefused
    {
        return new IssueRefused([new IssueProblem('form', $key)]);
    }
}
