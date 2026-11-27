<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Integration\Invoicing;

use Foresi\Invoicing\Contract\BilledWorkRefused;
use Foresi\Invoicing\Contract\BilledWorkRelease;
use Foresi\Timesheet\Application\ReleasedTimes;
use Foresi\Timesheet\Application\TimeEntries;
use Foresi\Timesheet\Domain\ReleasedTime;
use Foresi\Timesheet\Domain\TimeEntry;
use Psr\Clock\ClockInterface;

/**
 * The time of an invoice in the trash is free again, as in Clockify and Teamwork:
 * unbilled, editable and offered for invoicing. What it was billed as is kept
 * aside, so restoring the invoice bills each entry again on its line, at its rate
 * and for its minutes. Restoring refuses, as for an advance another invoice
 * deducts meanwhile, once an entry was invoiced elsewhere, changed or deleted:
 * the restored invoice would bill time twice or other time than it says. Editing
 * an issued invoice keeps the time with its rows, and a row removed frees its
 * time. Each change of an entry is audited under its task or project.
 */
final readonly class ReleaseTime implements BilledWorkRelease
{
    public function __construct(
        private TimeEntries $entries,
        private ReleasedTimes $released,
        private ClockInterface $clock,
    ) {
    }

    public function released(string $invoiceId): void
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        foreach ($this->entries->lockForInvoicing($this->entries->idsInvoicedBy($invoiceId)) as $entry) {
            $line = $entry->invoiceLineId();
            $rate = $entry->invoicedRate();
            $minutes = $entry->invoicedMinutes();
            if (in_array(null, [$line, $rate, $minutes], true)) {
                continue;
            }
            $this->released->add(new ReleasedTime($invoiceId, $entry->id(), $line, $rate, $minutes, EntryFingerprint::ofEntry($entry), $now));
            $entry->release();
        }
    }

    /**
     * @throws BilledWorkRefused with timesheet.invoice.problem.restore_time_invoiced or timesheet.invoice.problem.restore_time_changed
     */
    public function reclaim(string $invoiceId): void
    {
        $released = $this->released->ofInvoice($invoiceId);
        $locked = $this->entries->lockForInvoicing(array_map(static fn (ReleasedTime $time): string => $time->entryId(), $released));
        foreach ($released as $time) {
            $entry = $locked[$time->entryId()] ?? throw $this->refused('timesheet.invoice.problem.restore_time_changed');
            if ($entry->isInvoiced()) {
                throw $this->refused('timesheet.invoice.problem.restore_time_invoiced');
            }
            // The fingerprint holds whether the entry is billable and its minutes, which a running timer has none of.
            if (EntryFingerprint::ofEntry($entry) !== $time->fingerprint()) {
                throw $this->refused('timesheet.invoice.problem.restore_time_changed');
            }
            $this->bill($entry, $time);
            $this->released->remove($time);
        }
    }

    /**
     * The time of a row moved in the form stays with the row, and the time of a
     * row removed is free again, unbilled and offered for invoicing, as for a
     * line removed from an invoice in Clockify. Each entry is changed once, by
     * the line it was on before the save, so two rows that swap lines swap time.
     */
    public function revised(string $invoiceId, array $lines): void
    {
        foreach ($this->entries->lockForInvoicing($this->entries->idsInvoicedBy($invoiceId)) as $entry) {
            $line = $entry->invoiceLineId();
            if (null === $line || !array_key_exists($line, $lines)) {
                continue;
            }
            $now = $lines[$line];
            if (null === $now) {
                $entry->release();
            } elseif ($now !== $line) {
                $entry->moveToLine($now);
            }
        }
    }

    private function bill(TimeEntry $entry, ReleasedTime $time): void
    {
        try {
            $entry->invoice($time->invoiceId(), $time->lineId(), $time->rate(), $time->minutes());
        } catch (\DomainException) {
            throw $this->refused('timesheet.invoice.problem.restore_time_changed');
        }
    }

    private function refused(string $key): BilledWorkRefused
    {
        return new BilledWorkRefused($key);
    }
}
