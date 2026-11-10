<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Application;

use App\Invoicing\Application\IssueOrigin;
use App\Invoicing\Application\IssueProblem;
use App\Invoicing\Application\IssueRefused;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Work\Application\TimeEntries;
use App\Work\Domain\TimeEntry;

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
final readonly class TimeInvoiceOrigin implements IssueOrigin
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

    public function withNumber(string $text, string $number): string
    {
        return $text;
    }

    /**
     * @throws IssueRefused when the invoice is no invoice of the firm the time was priced for, or an entry changed since it was chosen
     */
    public function recordIn(Invoice $invoice, array $lines): void
    {
        if (DocumentKind::Invoice !== $invoice->kind() || $invoice->firm()->id() !== $this->selection->firmId) {
            throw $this->refused('timesheet.problem.other_document');
        }
        // The first row that kept a line's source bills its time; a copy of the row bills nothing.
        $rows = [];
        foreach ($this->sources as $position => $source) {
            if (null !== $source) {
                $rows[$source] ??= $lines[$position];
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
                $entry = $locked[$chosen->id] ?? throw $this->refused('timesheet.problem.time_changed');
                if ($entry->isInvoiced()) {
                    throw $this->refused('timesheet.problem.time_invoiced');
                }
                // The fingerprint holds whether the entry is billable and its minutes, which a running timer has none of.
                if (EntryFingerprint::ofEntry($entry) !== $chosen->fingerprint || true === $entry->task()?->isDeleted()) {
                    throw $this->refused('timesheet.problem.time_changed');
                }
                $this->bill($entry, $invoice, $row, $line, $chosen);
            }
        }
    }

    private function bill(TimeEntry $entry, Invoice $invoice, InvoiceLine $row, SelectedLine $line, SelectedEntry $chosen): void
    {
        try {
            $entry->invoice($invoice->id(), $row->id(), $line->rate, $chosen->minutes);
        } catch (\DomainException) {
            throw $this->refused('timesheet.problem.time_changed');
        }
    }

    private function refused(string $key): IssueRefused
    {
        return new IssueRefused([new IssueProblem('form', $key)]);
    }
}
