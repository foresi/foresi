<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Integration\CashFlow;

use Foresi\CashFlow\Contract\ExpectedFlow;
use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Contract\UndatedFlow;
use Foresi\CashFlow\Contract\UndatedFlowSource;
use Foresi\Deals\Application\BillingAmounts;
use Foresi\Deals\Application\SalesAccess;
use Foresi\Deals\Application\SalesStore;
use Foresi\Deals\Domain\BillingEntryKind;
use Foresi\Deals\Domain\BillingPlanEntry;
use Foresi\Deals\Domain\Deal;
use Foresi\Deals\Domain\DealLine;
use Foresi\Deals\Infrastructure\Api\SalesApiController;
use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\Schedule\Recurrence;
use Foresi\Shared\Domain\Schedule\RecurrenceEnd;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;
use Foresi\Shared\Domain\Schedule\RecurringSchedule;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The planned invoices of the won deals not in the trash that no invoice or
 * recurring invoice fulfils yet (the billing plan), certainty deal. A one-off
 * entry is one flow; a recurring one a flow per invoice its line makes from
 * the entry's day, every interval of its unit, for its cycles, as a recurring
 * invoice schedules its own (RecurringSchedule). The money comes in the
 * client's due days after the invoicing day.
 *
 * An entry without a day is undated: nothing assumes one (D1). The forecast
 * shows it apart, outside the balance, until someone plans its day. A
 * fulfilled entry yields nothing: its document is counted by Invoicing.
 */
final readonly class BillingPlanFlows implements UndatedFlowSource
{
    public const string KEY = 'deals.billing_plan';
    public const string SUBJECT = 'deal_billing_entry';

    public function __construct(
        private SalesAccess $access,
        private SalesStore $store,
        private PartyDirectory $parties,
        private ApiTokenContext $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function mayReadDetails(): bool
    {
        $grant = $this->tokens->grant();

        return !$grant instanceof ApiTokenGrant || $grant->allows(ApiScope::of('deals', false));
    }

    public function flows(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $today): iterable
    {
        $this->access->denyUnlessStaff();
        $zone = $today->getTimezone();
        $last = $to->format('Y-m-d');
        $context = new PlanContext($this->store, $this->parties);
        foreach ($this->store->openBillingEntries() as $entry) {
            $on = $entry->expectedOn();
            $deal = $context->deal($entry);
            if (!$on instanceof \DateTimeImmutable || !$deal instanceof Deal) {
                continue;
            }
            $first = new \DateTimeImmutable($on->format('Y-m-d'), $zone);
            $dueDays = $context->dueDays($deal);
            if (BillingEntryKind::OneOff === $entry->kind()) {
                $amount = $entry->amount();
                $due = $first->modify(\sprintf('+%d days', $dueDays));
                if ($amount instanceof Money && $due->format('Y-m-d') <= $last) {
                    yield $this->flow($entry, $deal, $amount, $due, $this->label($entry, $deal, null));
                }
                continue;
            }
            $line = $context->line($entry);
            $amount = $line instanceof DealLine ? BillingAmounts::ofLine($line) : null;
            if (!$line instanceof DealLine || !$amount instanceof Money) {
                continue;
            }
            $recurrence = $this->recurrence($line, $first);
            for ($n = 0;; ++$n) {
                $day = RecurringSchedule::occurrence($recurrence, $n);
                if (RecurringSchedule::isOver($recurrence, $n, $day)) {
                    break;
                }
                $due = new \DateTimeImmutable($day->format('Y-m-d'), $zone)->modify(\sprintf('+%d days', $dueDays));
                if ($due->format('Y-m-d') > $last) {
                    break;
                }
                yield $this->flow($entry, $deal, $amount, $due, $this->label($entry, $deal, $day));
            }
        }
    }

    public function undatedFlows(\DateTimeImmutable $today): iterable
    {
        $this->access->denyUnlessStaff();
        $context = new PlanContext($this->store, $this->parties);
        foreach ($this->store->openBillingEntries() as $entry) {
            $deal = $context->deal($entry);
            if ($entry->expectedOn() instanceof \DateTimeImmutable || !$deal instanceof Deal) {
                continue;
            }
            // A recurring entry without a day counts its first invoice: when it starts is what is missing.
            $line = $context->line($entry);
            $amount = BillingEntryKind::OneOff === $entry->kind() ? $entry->amount() : ($line instanceof DealLine ? BillingAmounts::ofLine($line) : null);
            if (!$amount instanceof Money || $amount->isZero()) {
                continue;
            }
            yield new UndatedFlow(self::KEY, $this->subject($entry, $deal), FlowDirection::In, $amount, FlowCategory::Revenue, FlowCertainty::Deal, $this->label($entry, $deal, null), $deal->clientPartyId());
        }
    }

    private function flow(BillingPlanEntry $entry, Deal $deal, Money $amount, \DateTimeImmutable $due, string $label): ExpectedFlow
    {
        return new ExpectedFlow(self::KEY, $this->subject($entry, $deal), FlowDirection::In, $amount, $due, FlowCategory::Revenue, FlowCertainty::Deal, $label, counterpartyId: $deal->clientPartyId());
    }

    private function subject(BillingPlanEntry $entry, Deal $deal): FlowSubject
    {
        return new FlowSubject(self::SUBJECT, $entry->id(), $this->urls->generate(SalesApiController::DEAL, ['dealId' => $deal->id()], UrlGeneratorInterface::ABSOLUTE_PATH), $deal->title());
    }

    /**
     * "Deal title – entry text", and the day of a recurring invoice after it.
     */
    private function label(BillingPlanEntry $entry, Deal $deal, ?\DateTimeImmutable $occurrence): string
    {
        $label = '' === $entry->text() ? $deal->title() : $deal->title().' – '.$entry->text();

        return $occurrence instanceof \DateTimeImmutable ? \sprintf('%s (%s)', $label, $occurrence->format('Y-m-d')) : $label;
    }

    /**
     * The schedule of a recurring line from the entry's first day: every
     * interval of its unit, for its cycles or with no end.
     */
    private function recurrence(DealLine $line, \DateTimeImmutable $first): Recurrence
    {
        $cycles = $line->cycles();

        return new Recurrence($first, $line->recurrenceUnit() ?? RecurrenceUnit::Month, $line->interval() ?? 1, false, null === $cycles ? RecurrenceEnd::Never : RecurrenceEnd::AfterCount, null, $cycles);
    }
}
