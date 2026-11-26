<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Sales\Application;

use Foresi\Sales\Application\Api\AddDealLineInput;
use Foresi\Sales\Application\Api\CreateBillingEntryInput;
use Foresi\Sales\Application\Api\DeleteBillingEntryInput;
use Foresi\Sales\Application\Api\LinkBillingEntryInput;
use Foresi\Sales\Application\Api\RemoveDealLineInput;
use Foresi\Sales\Application\Api\ReopenDealInput;
use Foresi\Sales\Application\Api\UpdateBillingEntryInput;
use Foresi\Sales\Application\Api\UpdateDealLineInput;
use Foresi\Sales\Application\Api\WinDealInput;
use Foresi\Sales\Application\BillingEntryView;
use Foresi\Sales\Application\BillingPlanWrites;
use Foresi\Sales\Domain\BillingEntryKind;
use Foresi\Sales\Domain\BillingPlanEntry;
use Foresi\Sales\Domain\Deal;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Sales\Support\SalesTestCase;

/**
 * The billing plan of a won deal: the starting plan on win, planned invoices
 * by amount or share of the one-off value, never more than that value, a day
 * or none, and nothing on a deal that is not won.
 */
final class BillingPlanTest extends SalesTestCase
{
    public function testWinningStartsThePlanWithoutADay(): void
    {
        // The Skácel case: one one-off line of 10,000 CZK, nothing invoiced.
        $audit = $this->deal('Initial e-shop audit');
        $this->typedLine($audit, 'Audit', '10000.00');
        $seo = $this->lines()->add(new AddDealLineInput($audit->id(), name: 'SEO', unitPrice: '14000.00', billingKind: 'recurring', recurrenceUnit: 'month', interval: 1));
        $this->lines()->add(new AddDealLineInput($audit->id(), name: 'Consulting', unit: 'h', quantity: '10', unitPrice: '1500.00', billingKind: 'time_based'));

        $this->deals()->win(new WinDealInput($audit->id()));

        $plan = $this->reads()->deal($audit->id())->billingPlan;
        self::assertSame([
            ['one_off', null, 1_000_000, null, ''],
            ['recurring', $seo->id(), 1_400_000, null, 'SEO'],
        ], array_map(static fn (BillingEntryView $view): array => [$view->entry->kind()->value, $view->entry->lineId(), $view->amount?->minor(), $view->entry->expectedOn(), $view->entry->text()], $plan->entries), 'One entry for the one-off lines, one per recurring line, none for the hourly one, and none with a day.');
        self::assertSame([BillingEntryView::PLANNED, BillingEntryView::PLANNED], array_map(static fn (BillingEntryView $view): string => $view->state, $plan->entries));
        self::assertSame([], $plan->notPlanned);
        self::assertSame(['CZK' => 2_400_000], array_map(static fn (Money $money): int => $money->minor(), $plan->notInvoiced()));
        self::assertCount(2, $this->events('sales.billing_entry.created'));

        // Reopened and won again, the deal keeps the plan it had.
        $this->deals()->reopen(new ReopenDealInput($audit->id()));
        $this->assertRefused('deal_not_won', fn (): BillingPlanEntry => $this->plans()->create(new CreateBillingEntryInput($audit->id(), amount: '1000.00')));
        $this->deals()->win(new WinDealInput($audit->id()));
        self::assertCount(2, $this->reads()->deal($audit->id())->billingPlan->entries);
        self::assertCount(2, $this->events('sales.billing_entry.created'));
    }

    public function testOneOffEntriesNeverExceedTheOneOffValue(): void
    {
        $deal = $this->won('12000.00');
        $start = $this->only($deal);
        // The deposit and the rest: half of it as a percentage, the starting entry lowered to the other half.
        $this->assertRefused('plan_exceeds_value', fn (): BillingPlanEntry => $this->plans()->create(new CreateBillingEntryInput($deal->id(), percent: '50')));
        $this->plans()->update(new UpdateBillingEntryInput($deal->id(), $start->id(), amount: '6000.00', expectedOn: '2026-12-15', text: 'Rest'));
        $deposit = $this->plans()->create(new CreateBillingEntryInput($deal->id(), percent: '50', expectedOn: '2026-11-30', text: 'Deposit'));

        self::assertSame(600_000, $deposit->amount()?->minor(), 'The percentage makes the amount, which is stored.');
        self::assertSame('2026-11-30', $deposit->expectedOn()?->format('Y-m-d'));
        self::assertSame(BillingEntryKind::OneOff, $deposit->kind());
        $refused = $this->assertRefused('plan_exceeds_value', fn (): BillingPlanEntry => $this->plans()->create(new CreateBillingEntryInput($deal->id(), amount: '0.01')));
        self::assertSame('amount', $refused->field());
        $this->assertRefused('percent_invalid', fn (): BillingPlanEntry => $this->plans()->create(new CreateBillingEntryInput($deal->id(), percent: '101')));
        $this->assertRefused('amount_and_percent', fn (): BillingPlanEntry => $this->plans()->create(new CreateBillingEntryInput($deal->id(), amount: '1.00', percent: '1')));
        $this->assertRefused('amount_invalid', fn (): BillingPlanEntry => $this->plans()->create(new CreateBillingEntryInput($deal->id(), amount: '0')));
        $this->assertRefused('expected_on_invalid', fn (): BillingPlanEntry => $this->plans()->create(new CreateBillingEntryInput($deal->id(), amount: '1.00', expectedOn: '2026-13-01')));
        $this->assertRefused('percent_no_value', fn (): BillingPlanEntry => $this->plans()->create(new CreateBillingEntryInput($deal->id(), currency: 'EUR', percent: '10')));
        $this->assertRefused('recurring_amount', fn (): BillingPlanEntry => $this->plans()->create(new CreateBillingEntryInput($deal->id(), 'recurring', amount: '1.00')));

        // Lowering the deposit leaves the difference not planned; the day may be taken away again.
        $this->plans()->update(new UpdateBillingEntryInput($deal->id(), $deposit->id(), amount: '5000.00', expectedOn: null));
        $plan = $this->reads()->deal($deal->id())->billingPlan;
        self::assertSame(['CZK' => 100_000], array_map(static fn (Money $money): int => $money->minor(), $plan->notPlanned));
        self::assertNull($this->entry($deal, $deposit->id())->expectedOn());
        self::assertSame(['entry_id' => $deposit->id(), 'deal_id' => $deal->id(), 'kind' => 'one_off'], array_reverse($this->events('sales.billing_entry.updated'))[0]);

        // A day before today makes it overdue.
        $this->plans()->update(new UpdateBillingEntryInput($deal->id(), $deposit->id(), expectedOn: '2026-11-20'));
        self::assertSame(BillingEntryView::OVERDUE, $this->view($deal, $deposit->id())->state);

        $this->plans()->delete(new DeleteBillingEntryInput($deal->id(), $deposit->id()));
        self::assertCount(1, $this->reads()->deal($deal->id())->billingPlan->entries);
        self::assertCount(1, $this->events('sales.billing_entry.deleted'));
    }

    public function testARecurringLineHasOneEntryAndTakesItAlong(): void
    {
        $deal = $this->deal('Ongoing SEO');
        $seo = $this->lines()->add(new AddDealLineInput($deal->id(), name: 'SEO', unitPrice: '14000.00', billingKind: 'recurring', recurrenceUnit: 'month', interval: 1));
        $this->deals()->win(new WinDealInput($deal->id()));

        $this->assertRefused('line_planned', fn (): BillingPlanEntry => $this->plans()->create(new CreateBillingEntryInput($deal->id(), 'recurring', lineId: $seo->id())));
        $this->assertRefused('amount_not_one_off', fn (): BillingPlanEntry => $this->plans()->update(new UpdateBillingEntryInput($deal->id(), $this->only($deal)->id(), amount: '1.00')));

        // A price change follows into the entry; a line that no longer recurs takes its entry along.
        $this->lines()->update(new UpdateDealLineInput($deal->id(), $seo->id(), unitPrice: '15000.00'));
        self::assertSame(1_500_000, $this->reads()->deal($deal->id())->billingPlan->entries[0]->amount?->minor());
        $this->lines()->update(new UpdateDealLineInput($deal->id(), $seo->id(), billingKind: 'one_off'));
        self::assertSame([], $this->reads()->deal($deal->id())->billingPlan->entries);

        $this->lines()->update(new UpdateDealLineInput($deal->id(), $seo->id(), billingKind: 'recurring', recurrenceUnit: 'month', interval: 1));
        $entry = $this->plans()->create(new CreateBillingEntryInput($deal->id(), 'recurring', lineId: $seo->id(), expectedOn: '2026-12-01'));
        self::assertNull($entry->amount(), 'A recurring entry stores no amount; its line prices it.');
        $this->lines()->remove(new RemoveDealLineInput($deal->id(), $seo->id()));
        self::assertSame([], $this->reads()->deal($deal->id())->billingPlan->entries);
    }

    public function testLinkingNeedsAModuleThatIssuesInvoices(): void
    {
        $deal = $this->won('10000.00');
        $entry = $this->only($deal);

        $this->assertRefused('document_type_mismatch', fn (): BillingPlanEntry => $this->plans()->link(new LinkBillingEntryInput($deal->id(), $entry->id(), 'recurring_invoice', '019a0000-0000-7000-8000-000000000001')));
        $this->assertRefused('document_unknown', fn (): BillingPlanEntry => $this->plans()->link(new LinkBillingEntryInput($deal->id(), $entry->id(), 'invoice', '019a0000-0000-7000-8000-000000000001')));
        $this->assertRefused('not_found', fn (): BillingPlanEntry => $this->plans()->link(new LinkBillingEntryInput($deal->id(), '019a0000-0000-7000-8000-000000000002', 'invoice', '019a0000-0000-7000-8000-000000000001')));
    }

    public function testOnlyStaffPlans(): void
    {
        $deal = $this->won('10000.00');
        $this->signIn($this->guest);

        $this->assertRefused('forbidden', fn (): BillingPlanEntry => $this->plans()->create(new CreateBillingEntryInput($deal->id(), amount: '1.00')));
    }

    private function won(string $price): Deal
    {
        $deal = $this->deal('Roof audit');
        $this->typedLine($deal, 'Audit', $price);

        return $this->deals()->win(new WinDealInput($deal->id()));
    }

    private function only(Deal $deal): BillingPlanEntry
    {
        $entries = $this->reads()->deal($deal->id())->billingPlan->entries;
        self::assertCount(1, $entries);

        return $entries[0]->entry;
    }

    private function view(Deal $deal, string $entryId): BillingEntryView
    {
        foreach ($this->reads()->deal($deal->id())->billingPlan->entries as $view) {
            if ($view->entry->id() === $entryId) {
                return $view;
            }
        }
        self::fail('The deal has no such entry.');
    }

    private function entry(Deal $deal, string $entryId): BillingPlanEntry
    {
        return $this->view($deal, $entryId)->entry;
    }

    private function plans(): BillingPlanWrites
    {
        return self::getContainer()->get(BillingPlanWrites::class);
    }
}
