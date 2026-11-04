<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\FirmReminders;
use App\Party\Domain\LegalEntity;

/**
 * The invoices of a firm worth a reminder on a day, oldest due first: those
 * RemindableInvoices finds, page after page, whose open amount reaches the
 * minimum. Paging on past what the open amounts leave out means invoices that
 * owe nothing never hold back one that is due, however many sit ahead of it.
 * The caller stops taking when it has enough; no page is read beyond that.
 */
final readonly class ReminderCandidates
{
    private const int PAGE = 500;
    /** One outstanding() call per this many invoices. */
    private const int BATCH = 100;

    public function __construct(private RemindableInvoices $remindable, private InvoiceBalances $balances)
    {
    }

    /**
     * @return \Generator<int, RemindableInvoice>
     */
    public function worthReminding(LegalEntity $firm, FirmReminders $settings, \DateTimeImmutable $today): \Generator
    {
        $after = null;
        do {
            $page = $this->remindable->due($firm, $settings, $today, self::PAGE, $after);
            foreach (array_chunk($page, self::BATCH) as $batch) {
                $outstanding = $this->balances->outstanding(array_map(static fn (RemindableInvoice $invoice): string => $invoice->invoiceId, $batch));
                foreach ($batch as $candidate) {
                    if ($settings->isWorthReminding($outstanding[$candidate->invoiceId])) {
                        yield $candidate;
                    }
                }
            }
            $last = end($page);
            $after = false === $last ? null : $last->invoiceId;
        } while (self::PAGE === \count($page));
    }
}
