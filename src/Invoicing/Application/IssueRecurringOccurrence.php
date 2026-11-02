<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\PeriodText;
use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\RecurringInvoiceLine;
use App\Invoicing\Domain\RecurringState;
use App\Tenancy\Application\TenantClock;

/**
 * Issues the invoice of one occurrence of a recurring template, at most once
 * however often it is called, by however many workers. The invoice is an ordinary
 * one, issued through IssueInvoice: issued today in the tenant's zone, the tax
 * point by the template's rule from that day, due its days later. The period
 * variables of the texts name the occurrence, so a late run still names the right
 * month; {document_number} names the number the invoice is given. The customer is
 * copied from the contact as it is now, and each line takes the rate of its kind on
 * the tax point.
 *
 * The invoice is built outside the transaction from one version of the template;
 * when the template is saved before the lock, it is built again, so it holds one
 * whole version, never parts of two.
 */
final readonly class IssueRecurringOccurrence
{
    private const int REBUILDS = 3;

    public function __construct(
        private RecurringInvoices $templates,
        private IssueInvoice $issue,
        private TenantClock $clock,
    ) {
    }

    /**
     * @param \DateTimeImmutable      $occurrence the date of the schedule to issue
     * @param \DateTimeImmutable|null $today      the tenant's today; TenantClock's when not given
     *
     * @return Invoice|null null when the occurrence is not the template's to issue: issued already,
     *                      by another worker too, not its next date, or the template is not active
     *
     * It reads the template as stored now, so it starts the session over: whatever was
     * loaded before is detached
     *
     * @throws IssueRefused              when a rule refuses the invoice; nothing is stored
     * @throws UnknownRecurringInvoice   also for a template of another tenant
     * @throws RecurringChangedMeanwhile when the template kept changing while it was built
     * @throws \LogicException           when the session holds unsaved changes
     */
    public function issue(string $recurringId, \DateTimeImmutable $occurrence, ?\DateTimeImmutable $today = null): ?Invoice
    {
        $today = new \DateTimeImmutable(($today ?? $this->clock->today())->format('Y-m-d'));
        for ($round = 1;; ++$round) {
            $template = $this->templates->current($recurringId);
            if (!$this->isNext($template, $occurrence)) {
                return null;
            }
            $origin = new RecurringOccurrence($this->templates, $template->id(), $occurrence, $template->version());
            try {
                return $this->issue->issue($this->input($template, $occurrence, $today), $origin);
            } catch (OccurrenceTaken) {
                return null;
            } catch (RecurringChangedMeanwhile $changed) {
                if ($round >= self::REBUILDS) {
                    throw $changed;
                }
            }
        }
    }

    private function isNext(RecurringInvoice $template, \DateTimeImmutable $occurrence): bool
    {
        $next = $template->nextOccurrence();

        return RecurringState::Active === $template->state() && $next instanceof \DateTimeImmutable && $next->format('Y-m-d') === $occurrence->format('Y-m-d');
    }

    private function input(RecurringInvoice $template, \DateTimeImmutable $occurrence, \DateTimeImmutable $today): InvoiceInput
    {
        $text = static fn (string $text): string => PeriodText::resolve($text, $occurrence);
        $lines = array_map(
            static fn (RecurringInvoiceLine $line): LineInput => new LineInput(
                $text($line->text()),
                $line->quantity(),
                $line->unit(),
                $line->price(),
                $line->priceType(),
                $line->treatment(),
                $line->rateKind(),
                $line->supplyCode(),
                $line->discount(),
            ),
            $this->templates->lines($template),
        );

        return new InvoiceInput(
            $template->firm()->id(),
            $template->series()?->id(),
            null,
            $template->customer()->id(),
            null,
            $today,
            $template->taxPoint()->for($today),
            $today->modify(\sprintf('+%d days', $template->dueDays())),
            $template->paymentMethod(),
            $template->bankAccount(),
            '',
            $template->constantSymbol(),
            $text($template->orderNumber()),
            $text($template->description()),
            $text($template->textBefore()),
            $text($template->textAfter()),
            $template->language(),
            $lines,
            variableSymbolFollowsNumber: true,
        );
    }
}
