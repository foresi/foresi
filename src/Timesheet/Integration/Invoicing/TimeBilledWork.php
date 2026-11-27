<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Integration\Invoicing;

use Foresi\Invoicing\Contract\BilledLine;
use Foresi\Invoicing\Contract\BilledWork;
use Foresi\Invoicing\Contract\BilledWorkDraft;
use Foresi\Invoicing\Contract\BilledWorkOrigin;
use Foresi\Invoicing\Contract\BilledWorkRefused;
use Foresi\Timesheet\Application\TimeEntries;

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
            $selection->clientId,
            $selection->currency,
            array_map(static fn (SelectedLine $line): BilledLine => new BilledLine($line->text, $line->hours, $line->unit, $line->rate, $line->key), $selection->lines),
        );
    }

    public function origin(string $reference, array $sources): BilledWorkOrigin
    {
        $selection = $this->selections->find($reference);
        if (!$selection instanceof TimeSelection) {
            throw new BilledWorkRefused('timesheet.invoice.problem.selection_gone');
        }

        return new TimeInvoiceOrigin($selection, $sources, $this->entries);
    }
}
