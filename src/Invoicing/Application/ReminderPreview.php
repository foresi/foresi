<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Invoicing\Domain\FirmReminders;

/**
 * Which invoices would be reminded tomorrow, so the owner sees it before
 * the first reminder leaves. The day is the instance's, as the reminder run counts it.
 * While reminders are off, it shows instead which first reminders the coming
 * days would bring if they were switched on today: switching on only reminds
 * invoices due from that day, so nothing older appears.
 */
final readonly class ReminderPreview
{
    public const int SHOWN = 10;
    /** How far ahead the preview of switching on looks. */
    public const int AHEAD_DAYS = 30;

    public function __construct(
        private ReminderSettings $settings,
        private ReminderCandidates $candidates,
        private InvoiceStore $store,
        private InstanceClock $clock,
        private InvoicePayments $payments,
    ) {
    }

    /**
     * @return list<InvoiceRow> at most SHOWN, oldest due date first
     */
    public function tomorrow(): array
    {
        $settings = $this->settings->get();
        $tomorrow = $this->today()->modify('+1 day');
        $rows = [];
        foreach ($this->candidates->worthReminding($settings, $tomorrow) as $candidate) {
            $rows[] = $this->row($candidate->invoiceId, $tomorrow);
            if (self::SHOWN === \count($rows)) {
                break;
            }
        }

        return $rows;
    }

    /**
     * The first reminders of the next AHEAD_DAYS days, were reminders switched on
     * today with the stored delays and minimum. Nothing is stored.
     *
     * @return list<PlannedReminder> at most SHOWN, soonest first
     */
    public function ifSwitchedOnToday(): array
    {
        $stored = $this->settings->get();
        $today = $this->today();
        // A copy that is never persisted, so the stored settings stay as they are.
        $settings = FirmReminders::standard();
        $settings->change(true, $stored->firstAfterDays(), $stored->everyDays(), $stored->minimum(), $today);
        $until = $today->modify(\sprintf('+%d days', self::AHEAD_DAYS));
        $planned = [];
        foreach ($this->candidates->worthReminding($settings, $until) as $candidate) {
            $row = $this->row($candidate->invoiceId, $today);
            $on = new \DateTimeImmutable($row->dueDate->format('Y-m-d'), $today->getTimezone())->modify(\sprintf('+%d days', $settings->firstAfterDays()));
            $planned[] = new PlannedReminder($row, $on);
            if (self::SHOWN === \count($planned)) {
                break;
            }
        }

        return $planned;
    }

    /**
     * Today, at its start, in the instance's zone.
     */
    public function today(): \DateTimeImmutable
    {
        return $this->clock->today();
    }

    private function row(string $id, \DateTimeImmutable $on): InvoiceRow
    {
        $invoice = $this->store->get($id);
        $paid = $this->payments->paidOf($invoice);
        $corrections = $this->payments->correctionsOf($invoice);
        $payment = PaymentState::of($invoice->payable(), $paid, $corrections);

        return new InvoiceRow(
            $invoice->id(),
            $invoice->number(),
            $invoice->issuer()->name,
            $invoice->customer()->name,
            $invoice->issueDate(),
            $invoice->dueDate(),
            $invoice->payable(),
            $invoice->isDelivered(),
            !$payment->isSettled() && $invoice->dueDate()->format('Y-m-d') < $on->format('Y-m-d'),
            $invoice->isTrashed(),
            $paid,
            $payment,
            corrections: $corrections,
        );
    }
}
