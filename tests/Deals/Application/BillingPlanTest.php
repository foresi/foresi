<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Deals\Application;

use Foresi\Deals\Application\Api\AddDealLineInput;
use Foresi\Deals\Application\Api\CreateBillingEntryInput;
use Foresi\Deals\Application\Api\DeleteBillingEntryInput;
use Foresi\Deals\Application\Api\LinkBillingEntryInput;
use Foresi\Deals\Application\Api\RemoveDealLineInput;
use Foresi\Deals\Application\Api\ReopenDealInput;
use Foresi\Deals\Application\Api\TrashDealInput;
use Foresi\Deals\Application\Api\UnlinkBillingEntryInput;
use Foresi\Deals\Application\Api\UpdateBillingEntryInput;
use Foresi\Deals\Application\Api\UpdateDealLineInput;
use Foresi\Deals\Application\Api\WinDealInput;
use Foresi\Deals\Application\BillingEntryView;
use Foresi\Deals\Application\BillingPlanWrites;
use Foresi\Deals\Domain\BillingEntryKind;
use Foresi\Deals\Domain\BillingPlanEntry;
use Foresi\Deals\Domain\Deal;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Deals\Support\SalesTestCase;

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
        $start = $this->only($deal);
        $this->lines()->update(new UpdateDealLineInput($deal->id(), $seo->id(), billingKind: 'one_off'));
        self::assertSame([], $this->reads()->deal($deal->id())->billingPlan->entries);
        self::assertSame([['entry_id' => $start->id(), 'deal_id' => $deal->id(), 'kind' => 'recurring']], $this->events('sales.billing_entry.deleted'));

        $this->lines()->update(new UpdateDealLineInput($deal->id(), $seo->id(), billingKind: 'recurring', recurrenceUnit: 'month', interval: 1));
        $entry = $this->plans()->create(new CreateBillingEntryInput($deal->id(), 'recurring', lineId: $seo->id(), expectedOn: '2026-12-01'));
        self::assertNull($entry->amount(), 'A recurring entry stores no amount; its line prices it.');
        $this->lines()->remove(new RemoveDealLineInput($deal->id(), $seo->id()));
        self::assertSame([], $this->reads()->deal($deal->id())->billingPlan->entries);
        self::assertSame(['entry_id' => $entry->id(), 'deal_id' => $deal->id(), 'kind' => 'recurring'], array_reverse($this->events('sales.billing_entry.deleted'))[0]);
        self::assertCount(2, $this->events('sales.billing_entry.deleted'));
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
        $entry = $this->only($deal);
        $this->signIn($this->guest);

        $this->assertRefused('forbidden', fn (): BillingPlanEntry => $this->plans()->create(new CreateBillingEntryInput($deal->id(), amount: '1.00')));
        $this->assertRefused('forbidden', fn (): BillingPlanEntry => $this->plans()->update(new UpdateBillingEntryInput($deal->id(), $entry->id(), text: 'Rest')));
        $this->assertRefused('forbidden', fn (): Deal => $this->plans()->delete(new DeleteBillingEntryInput($deal->id(), $entry->id())));
        $this->assertRefused('forbidden', fn (): BillingPlanEntry => $this->plans()->link(new LinkBillingEntryInput($deal->id(), $entry->id(), 'invoice', '019a0000-0000-7000-8000-000000000001')));
        $this->assertRefused('forbidden', fn (): BillingPlanEntry => $this->plans()->unlink(new UnlinkBillingEntryInput($deal->id(), $entry->id())));
    }

    public function testRefusesWhatIsNoPlannedInvoice(): void
    {
        $deal = $this->won('10000.00');
        $entry = $this->only($deal);
        $unpriced = $this->lines()->add(new AddDealLineInput($deal->id(), name: 'Support', billingKind: 'recurring', recurrenceUnit: 'month', interval: 1));

        self::assertSame('kind', $this->assertRefused('kind_unknown', fn (): BillingPlanEntry => $this->plans()->create(new CreateBillingEntryInput($deal->id(), 'weekly', amount: '1.00')))->field());
        self::assertSame('lineId', $this->assertRefused('line_required', fn (): BillingPlanEntry => $this->plans()->create(new CreateBillingEntryInput($deal->id(), 'recurring')))->field());
        $this->assertRefused('line_not_recurring', fn (): BillingPlanEntry => $this->plans()->create(new CreateBillingEntryInput($deal->id(), 'recurring', lineId: $unpriced->id())));
        self::assertSame('type', $this->assertRefused('document_type_unknown', fn (): BillingPlanEntry => $this->plans()->link(new LinkBillingEntryInput($deal->id(), $entry->id(), 'order', '019a0000-0000-7000-8000-000000000001')))->field());

        // A won deal in the trash has no plan to change.
        $this->deals()->trash(new TrashDealInput($deal->id()));
        $this->assertRefused('not_found', fn (): BillingPlanEntry => $this->plans()->create(new CreateBillingEntryInput($deal->id(), amount: '1.00')));
    }

    public function testTheCurrencyMayBeLeftOutOnlyWhenTheOneOffValueHasOne(): void
    {
        $none = $this->deal('Hosting');
        $this->lines()->add(new AddDealLineInput($none->id(), name: 'Hosting', unitPrice: '500.00', billingKind: 'recurring', recurrenceUnit: 'month', interval: 1));
        $this->deals()->win(new WinDealInput($none->id()));
        $several = $this->deal('Two currencies');
        $this->typedLine($several, 'Audit', '1000.00');
        $this->typedLine($several, 'Report', '100.00', currency: 'EUR');
        $this->deals()->win(new WinDealInput($several->id()));

        self::assertSame('Name the currency of the amount: the deal has one-off lines in none.', $this->assertRefused('currency_required', fn (): BillingPlanEntry => $this->plans()->create(new CreateBillingEntryInput($none->id(), amount: '1.00')))->getMessage());
        self::assertSame('Name the currency of the amount: the deal has one-off lines in several.', $this->assertRefused('currency_required', fn (): BillingPlanEntry => $this->plans()->create(new CreateBillingEntryInput($several->id(), amount: '1.00')))->getMessage());
        // A currency the deal has no one-off value in has nothing to plan.
        self::assertSame('The one-off entries would exceed the deal\'s one-off value in EUR; 0.00 is left to plan.', $this->assertRefused('plan_exceeds_value', fn (): BillingPlanEntry => $this->plans()->create(new CreateBillingEntryInput($none->id(), amount: '0.01', currency: 'EUR')))->getMessage());
    }

    public function testAmountsPercentsAndDaysAreReadAsTyped(): void
    {
        $deal = $this->won('1000.01');
        $this->plans()->delete(new DeleteBillingEntryInput($deal->id(), $this->only($deal)->id()));

        $whole = $this->plans()->create(new CreateBillingEntryInput($deal->id(), percent: ' 100 ', expectedOn: ' 2026-12-15 '));
        self::assertSame([100_001, '2026-12-15'], [$whole->amount()?->minor(), $whole->expectedOn()?->format('Y-m-d')]);
        $this->plans()->delete(new DeleteBillingEntryInput($deal->id(), $whole->id()));
        $half = $this->plans()->create(new CreateBillingEntryInput($deal->id(), percent: '50', expectedOn: '  '));
        self::assertSame(50_001, $half->amount()?->minor(), 'Half a minor unit rounds up.');
        self::assertNull($half->expectedOn());
        $typed = $this->plans()->create(new CreateBillingEntryInput($deal->id(), amount: ' 1.00 '));
        self::assertSame(100, $typed->amount()?->minor());

        foreach (['0', '0.00', '5x', '100.01'] as $percent) {
            self::assertSame('percent', $this->assertRefused('percent_invalid', fn (): BillingPlanEntry => $this->plans()->create(new CreateBillingEntryInput($deal->id(), percent: $percent)))->field(), $percent);
        }
        self::assertSame('amount', $this->assertRefused('amount_invalid', fn (): BillingPlanEntry => $this->plans()->create(new CreateBillingEntryInput($deal->id(), amount: '0.00')))->field());

        // A share that rounds to nothing is refused on the percent.
        $small = $this->won('0.01');
        self::assertSame('percent', $this->assertRefused('amount_invalid', fn (): BillingPlanEntry => $this->plans()->create(new CreateBillingEntryInput($small->id(), percent: '1')))->field());
    }

    public function testAChangeIsCheckedAgainstTheValueAndACurrencyAloneKeepsTheAmount(): void
    {
        $deal = $this->deal('Two currencies');
        $this->typedLine($deal, 'Audit', '500.00');
        $this->typedLine($deal, 'Report', '1000.00', currency: 'EUR');
        $this->deals()->win(new WinDealInput($deal->id()));
        [$czk, $eur] = array_map(static fn (BillingEntryView $view): BillingPlanEntry => $view->entry, $this->reads()->deal($deal->id())->billingPlan->entries);
        self::assertSame(['CZK', 'EUR'], [$czk->amount()?->currency()->value, $eur->amount()?->currency()->value]);

        self::assertSame('The one-off entries would exceed the deal\'s one-off value in CZK; 500.00 is left to plan.', $this->assertRefused('plan_exceeds_value', fn (): BillingPlanEntry => $this->plans()->update(new UpdateBillingEntryInput($deal->id(), $czk->id(), amount: '500.01')))->getMessage());

        $this->plans()->delete(new DeleteBillingEntryInput($deal->id(), $eur->id()));
        $this->plans()->update(new UpdateBillingEntryInput($deal->id(), $czk->id(), currency: 'EUR'));
        $moved = $this->entry($deal, $czk->id());
        self::assertSame([50_000, 'EUR'], [$moved->amount()?->minor(), $moved->amount()?->currency()->value]);

        // What is planned is never more than the value: the left over is said, or none when the plan is above it.
        $this->plans()->update(new UpdateBillingEntryInput($deal->id(), $czk->id(), amount: '600.00'));
        self::assertSame('The one-off entries would exceed the deal\'s one-off value in EUR; 400.00 is left to plan.', $this->assertRefused('plan_exceeds_value', fn (): BillingPlanEntry => $this->plans()->create(new CreateBillingEntryInput($deal->id(), amount: '400.01', currency: 'EUR')))->getMessage());
        $created = $this->plans()->create(new CreateBillingEntryInput($deal->id(), amount: '400.00', currency: 'EUR', text: 'Rest'));
        self::assertSame(['entry_id' => $created->id(), 'deal_id' => $deal->id(), 'kind' => 'one_off'], array_reverse($this->events('sales.billing_entry.created'))[0]);
        self::assertCount(3, $this->events('sales.billing_entry.created'));
        $report = $this->reads()->deal($deal->id())->lines[1];
        $this->lines()->update(new UpdateDealLineInput($deal->id(), $report->id(), unitPrice: '900.00'));
        self::assertSame('The one-off entries would exceed the deal\'s one-off value in EUR; 0 is left to plan.', $this->assertRefused('plan_exceeds_value', fn (): BillingPlanEntry => $this->plans()->create(new CreateBillingEntryInput($deal->id(), amount: '1.00', currency: 'EUR')))->getMessage());
    }

    public function testThePlanSaysWhatIsLeftToPlanAndToInvoicePerCurrency(): void
    {
        $deal = $this->deal('Shop and SEO');
        $this->typedLine($deal, 'Report', '1000.00', currency: 'EUR');
        $this->typedLine($deal, 'Audit', '10000.00');
        $this->lines()->add(new AddDealLineInput($deal->id(), name: 'SEO', quantity: '2', unitPrice: '14000.00', billingKind: 'recurring', recurrenceUnit: 'month', interval: 1));
        $this->deals()->win(new WinDealInput($deal->id()));
        [$audit, $report] = array_map(static fn (BillingEntryView $view): BillingPlanEntry => $view->entry, $this->reads()->deal($deal->id())->billingPlan->entries);
        self::assertSame([1_000_000, 100_000], [$audit->amount()?->minor(), $report->amount()?->minor()]);

        $this->plans()->delete(new DeleteBillingEntryInput($deal->id(), $audit->id()));
        $this->plans()->update(new UpdateBillingEntryInput($deal->id(), $report->id(), expectedOn: '2026-11-23'));

        $plan = $this->reads()->deal($deal->id())->billingPlan;
        self::assertSame(['CZK' => 1_000_000], array_map(static fn (Money $money): int => $money->minor(), $plan->notPlanned), 'A recurring entry is not part of the one-off value.');
        self::assertSame(['CZK' => 2_800_000, 'EUR' => 100_000], array_map(static fn (Money $money): int => $money->minor(), $plan->notInvoiced()), 'Price × quantity of the recurring line, the currencies in their order.');
        self::assertSame(BillingEntryView::PLANNED, $this->view($deal, $report->id())->state, 'Due today is not overdue.');

        // An odd half of a minor unit rounds up.
        $odd = $this->deal('Odd hosting');
        $this->lines()->add(new AddDealLineInput($odd->id(), name: 'Hosting', quantity: '1.5', unitPrice: '3.33', billingKind: 'recurring', recurrenceUnit: 'month', interval: 1));
        $this->deals()->win(new WinDealInput($odd->id()));
        self::assertSame(500, $this->reads()->deal($odd->id())->billingPlan->entries[0]->amount?->minor());
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
