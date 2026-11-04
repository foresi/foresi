<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\FirmReminders;
use App\Invoicing\Domain\SendPurpose;
use App\Party\Application\LegalEntities;
use App\Party\Domain\LegalEntity;
use App\Tenancy\Application\Schedule\DailyPeriod;
use App\Tenancy\Application\Schedule\ScheduledJob;
use App\Tenancy\Application\TenantClock;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Reminds customers of overdue invoices, once a day from 08:00 in the tenant's
 * zone, business hours for a mail about money. For each active firm with
 * reminders on it reads the invoices worth their next reminder, page after page
 * until the firm's daily budget is taken, and queues one reminder per invoice
 * through SendInvoice, as the send dialog would: the contact's addresses for
 * invoices, the firm's reminder text in the document's language. Nothing is sent
 * while the gate is closed, so a paid invoice nobody recorded is never reminded.
 *
 * A step another run took first is no error; that run has it. A refusal of one
 * invoice is logged and the next one goes on; a firm out of its sending budget
 * waits for the next day, when its invoices are still due. Anything else is
 * thrown, so the scheduler gives the day back and tries again; what was queued
 * stays queued and is not queued twice.
 */
final readonly class SendReminders implements ScheduledJob
{
    public const string NAME = 'invoice-reminders';
    public const int START_HOUR = 8;
    /** The firm's daily sending budget: more could not leave today anyway. */
    private const int MAX_PER_FIRM = 500;

    public function __construct(
        private LegalEntities $firms,
        private ReminderSettings $settings,
        private ReminderCandidates $candidates,
        private InvoiceStore $store,
        private SendDraft $drafts,
        private SendInvoice $sending,
        private TenantClock $tenantClock,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function period(\DateTimeImmutable $now, \DateTimeZone $zone): string
    {
        return DailyPeriod::of($now, $zone, self::START_HOUR);
    }

    public function run(?string $lastPeriod): void
    {
        if (!$this->settings->canSwitchOn()) {
            return;
        }
        $zone = $this->tenantClock->timezone();
        // The day of the period, so a run given back and retried after midnight still works for its own day.
        $today = new \DateTimeImmutable($this->period(\DateTimeImmutable::createFromInterface($this->clock->now()), $zone), $zone);
        foreach ($this->firms->active() as $firm) {
            $settings = $this->settings->for($firm);
            if ($settings->enabled()) {
                $this->remind($firm, $settings, $today);
            }
        }
    }

    private function remind(LegalEntity $firm, FirmReminders $settings, \DateTimeImmutable $today): void
    {
        $tried = 0;
        foreach ($this->candidates->worthReminding($firm, $settings, $today) as $candidate) {
            if (self::MAX_PER_FIRM === $tried++) {
                return;
            }
            try {
                $this->send($candidate, $today);
            } catch (SendRefused $refused) {
                if (SendRefused::REMINDER_TAKEN === $refused->reason) {
                    continue;
                }
                $this->logger->warning('The reminder {step} of invoice {invoice} was not queued: {reason}.', ['step' => $candidate->nextStep, 'invoice' => $candidate->invoiceId, 'reason' => $refused->reason]);
                if (SendRefused::RATE_LIMITED === $refused->reason) {
                    return;
                }
            }
        }
    }

    /**
     * @throws SendRefused
     */
    private function send(RemindableInvoice $candidate, \DateTimeImmutable $today): void
    {
        $invoice = $this->store->get($candidate->invoiceId);
        $draft = $this->drafts->forReminder($invoice, $candidate->nextStep, $today);
        $to = [];
        $cc = [];
        foreach ($draft->recipients as $recipient) {
            if (!$recipient->checked) {
                continue;
            }
            if (SendRecipient::TO === $recipient->field) {
                $to[] = $recipient->address;
            } else {
                $cc[] = $recipient->address;
            }
        }

        // No ticked address refuses with NO_RECIPIENT, which is logged rather than skipped in silence.
        $this->sending->send(new SendRequest($invoice->id(), $to, $cc, false, $draft->subject, $draft->body, SendPurpose::Reminder, $candidate->nextStep));
    }
}
