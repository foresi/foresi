<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Application;

use App\Invoicing\Application\BilledLine;
use App\Invoicing\Application\BilledWork;
use App\Invoicing\Application\BilledWorkDraft;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\IssueOrigin;
use App\Invoicing\Application\IssueProblem;
use App\Invoicing\Application\IssueRefused;
use App\Invoicing\Application\LineInput;
use App\Work\Application\TimeEntries;

/**
 * Time as the work an invoice bills: the time chosen in the dialog opens the
 * invoice form with its lines, and issuing the form bills it.
 */
final readonly class TimeBilledWork implements BilledWork
{
    public function __construct(
        private TimeSelections $selections,
        private TimeEntries $entries,
    ) {
    }

    public function draft(string $reference): ?BilledWorkDraft
    {
        $selection = $this->selections->find($reference);
        if (!$selection instanceof TimeSelection) {
            return null;
        }

        return new BilledWorkDraft(
            $reference,
            $selection->firmId,
            $selection->clientId,
            $selection->currency,
            array_map(static fn (SelectedLine $line): BilledLine => new BilledLine($line->text, $line->hours, $line->unit, $line->rate, $line->key), $selection->lines),
        );
    }

    public function origin(InvoiceInput $input): IssueOrigin
    {
        $selection = null === $input->billedWork ? null : $this->selections->find($input->billedWork);
        if (!$selection instanceof TimeSelection) {
            throw new IssueRefused([new IssueProblem('form', 'timesheet.problem.selection_gone')]);
        }

        return new TimeInvoiceOrigin($selection, array_map(static fn (LineInput $line): ?string => $line->source, $input->lines), $this->entries);
    }
}
