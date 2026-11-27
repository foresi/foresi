<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Integration\Invoicing;

use Foresi\Invoicing\Contract\BilledWorkOrigin;
use Foresi\Invoicing\Contract\BilledWorkRefused;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Timesheet\Application\TimeEntries;
use Foresi\Timesheet\Domain\TimeEntry;

/**
 * Time chosen in the dialog, as what issues its invoice: in the transaction
 * that stores the invoice, every entry whose line is still on it is locked and
 * billed on that line, with the rate and the rounded minutes it was chosen
 * with. A line of the form finds its time by the source it kept, so its text,
 * price and quantity may be edited without changing the entries (Clockify);
 * the entries of a line removed from the form stay unbilled.
 *
 * An entry invoiced, edited or deleted since it was chosen refuses the whole
 * invoice rather than billing time twice or other time than was shown.
 */
final readonly class TimeInvoiceOrigin implements BilledWorkOrigin
{
    /**
     * @param list<?string> $sources the source of each line of the input, in order
     */
    public function __construct(
        private TimeSelection $selection,
        private array $sources,
        private TimeEntries $entries,
    ) {
    }

    /**
     * @throws BilledWorkRefused when the document is no invoice, or an entry changed since it was chosen
     */
    public function recordIn(string $invoiceId, DocumentKind $kind, array $lineIds): void
    {
        if (DocumentKind::Invoice !== $kind) {
            throw $this->refused('timesheet.invoice.problem.other_document');
        }
        // The first row that kept a line's source bills its time; a copy of the row bills nothing.
        $rows = [];
        foreach ($this->sources as $position => $source) {
            if (null !== $source) {
                $rows[$source] ??= $lineIds[$position];
            }
        }
        $billed = array_filter($this->selection->lines, static fn (SelectedLine $line): bool => isset($rows[$line->key]));
        $ids = [];
        foreach ($billed as $line) {
            foreach ($line->entries as $entry) {
                $ids[] = $entry->id;
            }
        }
        $locked = $this->entries->lockForInvoicing($ids);
        foreach ($billed as $line) {
            $row = $rows[$line->key];
            foreach ($line->entries as $chosen) {
                $entry = $locked[$chosen->id] ?? throw $this->refused('timesheet.invoice.problem.time_changed');
                if ($entry->isInvoiced()) {
                    throw $this->refused('timesheet.invoice.problem.time_invoiced');
                }
                // The fingerprint holds whether the entry is billable and its minutes, which a running timer has none of.
                if (EntryFingerprint::ofEntry($entry) !== $chosen->fingerprint || $entry->isDeleted() || true === $entry->task()?->isDeleted()) {
                    throw $this->refused('timesheet.invoice.problem.time_changed');
                }
                $this->bill($entry, $invoiceId, $row, $line, $chosen);
            }
        }
    }

    private function bill(TimeEntry $entry, string $invoiceId, string $lineId, SelectedLine $line, SelectedEntry $chosen): void
    {
        try {
            $entry->invoice($invoiceId, $lineId, $line->rate, $chosen->minutes);
        } catch (\DomainException) {
            throw $this->refused('timesheet.invoice.problem.time_changed');
        }
    }

    private function refused(string $key): BilledWorkRefused
    {
        return new BilledWorkRefused($key);
    }
}
