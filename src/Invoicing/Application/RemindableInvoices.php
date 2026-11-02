<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\FirmReminders;
use App\Party\Domain\LegalEntity;

/**
 * The invoices of a firm that are due for a reminder on a day, by every rule but
 * the open amount, which the caller reads from InvoiceBalances and checks with
 * FirmReminders::isWorthReminding(). An invoice qualifies when it is outside the
 * trash, delivered, due on or after the day reminders were switched on, its
 * contact is reminded for this firm, it has had fewer than MAX_STEPS reminders,
 * there is an address to send to, and the day has come: the due date plus the
 * first delay for the first reminder, the previous reminder's day plus the next
 * delay after that. At most one reminder per invoice, so a missed day never
 * sends two.
 */
interface RemindableInvoices
{
    /**
     * @param \DateTimeImmutable $today the firm's day, in the firm's time zone
     *
     * @return list<RemindableInvoice> oldest due date first
     */
    public function due(LegalEntity $firm, FirmReminders $settings, \DateTimeImmutable $today, int $limit): array;
}
