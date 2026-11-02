<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\RecurringDelivery;
use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\RecurringState;
use App\Party\Application\TransactionalWork;
use App\Tenancy\Application\Schedule\DailyPeriod;
use App\Tenancy\Application\Schedule\ScheduledJob;
use App\Tenancy\Application\TenantClock;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Issues the invoices of recurring templates once a day from 05:00 in the
 * tenant's zone, so the first of the month's invoices leave early that morning.
 * Each active template due by today issues every occurrence from its next date
 * up to today, oldest first, each once whatever else runs (IssueRecurringOccurrence),
 * at most MAX_PER_RUN per template: a worker that was down catches up, the rest
 * follows the next day. Each invoice is then sent or held for review
 * (DeliverRecurringInvoice); one more than LATE_DAYS late is held, as nobody
 * expects it any more without a look.
 *
 * A template whose world moved, a customer archived for the firm, a contact
 * without an invoicing address, a kind of rate gone on the tax point, a firm
 * without a mail server, is never skipped in silence: the refusal is recorded on
 * the template, the firm is told by e-mail and the shell shows it, and the next
 * template runs. A refused issue keeps the next date, so the next run tries again;
 * a template that runs clean again clears its failure. A send that breaks on
 * anything else is logged and recorded the same way (UNEXPECTED): its invoice is
 * issued and waits to be sent, and the other templates still run. Once the
 * firm's sending budget refuses a send, the firm's later templates of the run are
 * issued and held for review without trying again, and the firm hears of all of
 * them in one mail at the end of the run (RecurringAlerts). Anything else
 * while issuing is thrown, so the scheduler gives the day back and the next tick
 * retries it; what was issued stays issued and is not issued twice.
 *
 * The run works by ids: issuing starts the session over, so nothing loaded
 * before it is used after it.
 */
final readonly class IssueRecurringInvoices implements ScheduledJob
{
    public const string NAME = 'recurring-invoices';
    public const int START_HOUR = 5;
    /** An occurrence up to this many days late is still sent; a later one waits for review. */
    public const int LATE_DAYS = 3;
    public const int MAX_PER_RUN = 24;
    /** The failure of a send that broke on something no rule foresaw. */
    public const string UNEXPECTED = 'send.unexpected';
    /** The failure of a send the firm's budget refused; the firm's later templates of the run are held with it. */
    public const string RATE_LIMITED = 'send.'.SendRefused::RATE_LIMITED;

    public function __construct(
        private RecurringInvoices $templates,
        private IssueRecurringOccurrence $occurrences,
        private DeliverRecurringInvoice $delivery,
        private RecurringAlerts $alerts,
        private TransactionalWork $work,
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

    /**
     * The catch-up follows each template's next date, which already names what was
     * missed, so the last period is not needed.
     */
    public function run(?string $lastPeriod): void
    {
        // The day of the period, so a run given back and retried after midnight still works for its own day.
        $today = $this->today();
        /** @var array<string, true> $spent firms whose sending budget ran out in this run */
        $spent = [];
        /** @var array<string, string> $failures the code of each template refused in this run */
        $failures = [];
        try {
            foreach ($this->templates->due($today) as $id) {
                $failure = $this->runTemplate($id, $today, $spent);
                if (null !== $failure) {
                    $failures[$id] = $failure;
                }
            }
        } finally {
            // One mail per firm for the whole run, also when it is given back halfway.
            $this->alerts->alertAll($failures, $today);
        }
    }

    /**
     * Issues and sends what one template has due today, as the run does for each,
     * for a template saved or resumed after the day's run (IssueAfterTodaysRun).
     * A template with nothing due, or gone meanwhile, is left as it is.
     */
    public function runOne(string $id): void
    {
        $today = $this->today();
        try {
            if (!$this->dueOccurrence($this->templates->current($id), $today) instanceof \DateTimeImmutable) {
                return;
            }
        } catch (UnknownRecurringInvoice) {
            return;
        }
        $spent = [];
        $failure = null;
        try {
            $failure = $this->runTemplate($id, $today, $spent);
        } finally {
            $this->alerts->alertAll(null === $failure ? [] : [$id => $failure], $today);
        }
    }

    /**
     * The tenant's day the run works for: before START_HOUR it is still the day before.
     */
    public function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->period($this->now(), $this->tenantClock->timezone()));
    }

    /**
     * @param array<string, true> $spent firms out of their budget; a refusal for the budget adds the template's
     *
     * @return string|null the failure recorded on the template, if any
     */
    private function runTemplate(string $id, \DateTimeImmutable $today, array &$spent): ?string
    {
        $failure = null;
        for ($issued = 0; $issued < self::MAX_PER_RUN; ++$issued) {
            $current = $this->templates->current($id);
            $firmId = $current->firm()->id();
            $occurrence = $this->dueOccurrence($current, $today);
            if (!$occurrence instanceof \DateTimeImmutable) {
                break;
            }
            try {
                $invoice = $this->occurrences->issue($id, $occurrence, $today);
            } catch (IssueRefused $refused) {
                $failure = $refused->problems[0]->key ?? 'invoice.problem.refused';
                break;
            } catch (UnsupportedCountry) {
                $failure = 'invoice.problem.firm_country_unsupported';
                break;
            }
            if (!$invoice instanceof Invoice) {
                // Taken meanwhile, by "issue now" or another worker; the next round reads where it stands.
                continue;
            }
            $template = $this->templates->get($id);
            $late = $this->isLate($occurrence, $today);
            if (isset($spent[$firmId]) && !$late && RecurringDelivery::Send === $template->delivery()) {
                // The firm's budget ran out earlier in this run: held without asking again, until someone sends it.
                $failure = self::RATE_LIMITED;
                continue;
            }
            $invoiceId = $invoice->id();
            try {
                $outcome = $this->delivery->deliver($invoice, $template, $late);
            } catch (\Throwable $error) {
                // The invoice is issued and stays so; it waits to be sent like a refused one, and the next template runs.
                $this->logger->error('Sending invoice {invoice} of recurring invoice {template} failed unexpectedly.', ['invoice' => $invoiceId, 'template' => $id, 'exception' => $error]);
                $failure = self::UNEXPECTED;
                break;
            }
            if ($outcome->isRefused()) {
                $failure = $outcome->code;
                if (self::RATE_LIMITED === $failure) {
                    $spent[$firmId] = true;
                }
            }
        }
        $this->settle($id, $failure);

        return $failure;
    }

    private function dueOccurrence(RecurringInvoice $template, \DateTimeImmutable $today): ?\DateTimeImmutable
    {
        $next = $template->nextOccurrence();
        if (RecurringState::Active !== $template->state() || !$next instanceof \DateTimeImmutable || $next->format('Y-m-d') > $today->format('Y-m-d')) {
            return null;
        }

        return $next;
    }

    private function isLate(\DateTimeImmutable $occurrence, \DateTimeImmutable $today): bool
    {
        $days = new \DateTimeImmutable($occurrence->format('Y-m-d'))->diff(new \DateTimeImmutable($today->format('Y-m-d')))->days;

        return $days > self::LATE_DAYS;
    }

    private function settle(string $id, ?string $failure): void
    {
        if (null === $failure) {
            if (null !== $this->templates->current($id)->lastFailureCode()) {
                $this->work->run(function () use ($id): void {
                    $this->templates->lock($id)->clearFailure();
                });
            }

            return;
        }
        $this->logger->warning('The recurring invoice {template} was refused: {code}.', ['template' => $id, 'code' => $failure]);
        $at = $this->now();
        $this->work->run(function () use ($id, $failure, $at): void {
            $this->templates->lock($id)->recordFailure($at, $failure);
        });
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
