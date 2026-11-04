<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\RecurringState;

/**
 * One occurrence of a recurring template as the origin of the invoice issued for it.
 * Inside the issue transaction it locks the template and takes the occurrence only
 * when the template is active, the occurrence is its next date, no invoice holds it
 * yet, and the template is still the version the invoice was built from. Then the
 * invoice names the occurrence and the template moves on, in the same commit; the
 * unique key on the invoice stops whatever skipped this lock.
 *
 * A text naming {document_number} gets the number the invoice is given.
 */
final readonly class RecurringOccurrence implements IssueOrigin
{
    public const string DOCUMENT_NUMBER = '{document_number}';

    /**
     * @param int $version the version of the template the invoice was built from
     */
    public function __construct(
        private RecurringInvoices $templates,
        private string $templateId,
        private \DateTimeImmutable $occurrence,
        private int $version,
    ) {
    }

    public function withNumber(string $text, string $number): string
    {
        return str_replace(self::DOCUMENT_NUMBER, $number, $text);
    }

    /**
     * @throws OccurrenceTaken           when the occurrence is not the template's to issue any more
     * @throws RecurringChangedMeanwhile when the template was saved since the invoice was built
     */
    public function recordIn(Invoice $invoice): void
    {
        $template = $this->templates->lockDuringWrite($this->templateId);
        $next = $template->nextOccurrence();
        if (RecurringState::Active !== $template->state() || !$next instanceof \DateTimeImmutable || $next->format('Y-m-d') !== $this->occurrence->format('Y-m-d')) {
            throw new OccurrenceTaken();
        }
        if ($this->templates->occurrenceIssued($this->templateId, $this->occurrence)) {
            throw new OccurrenceTaken();
        }
        if ($template->version() !== $this->version) {
            throw new RecurringChangedMeanwhile();
        }
        $invoice->issuedFrom($template->id(), $this->occurrence);
        $template->advance($this->occurrence);
    }
}
