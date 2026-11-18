<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Application;

use Foresi\Invoicing\Application\BilledLine;
use Foresi\Invoicing\Application\BilledWork;
use Foresi\Invoicing\Application\BilledWorkDraft;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\IssueOrigin;
use Foresi\Invoicing\Application\IssueProblem;
use Foresi\Invoicing\Application\IssueRefused;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Work\Application\TimeEntries;

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
