<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\CashFlow\Application;

use App\CashFlow\Application\CashFlowRefused;
use App\CashFlow\Application\ChangePlannedItemInput;
use App\CashFlow\Application\DeletePlannedItemInput;
use App\CashFlow\Application\EndPlannedItemInput;
use App\CashFlow\Application\PlannedItemReads;
use App\CashFlow\Application\SkipPlannedOccurrenceInput;
use App\CashFlow\Contract\FlowCategory;
use App\CashFlow\Contract\FlowDirection;
use App\CashFlow\Domain\PlannedItem;
use App\Shared\Application\RefusalKind;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Schedule\RecurrenceEnd;
use App\Shared\Domain\Schedule\RecurrenceUnit;
use App\Tests\CashFlow\Support\CashFlowTestCase;

/**
 * Planned items over the API and MCP (C5): one-off or recurring, changed in
 * part, an occurrence skipped, a recurring one ended, deleted; every write
 * records its cash-flow.planned_item event and a guest writes nothing.
 */
final class PlannedItemWritesTest extends CashFlowTestCase
{
    public function testCreatesAOneOffItemAndRecordsItsEvent(): void
    {
        $item = $this->planned(name: ' Office rent ', counterpartyId: $this->customer->id());

        $stored = $this->reloaded($item);
        self::assertSame('Office rent', $stored->name());
        self::assertSame(FlowDirection::Out, $stored->direction());
        self::assertSame(FlowCategory::Rent, $stored->category());
        self::assertSame(1000000, $stored->amount()->minor());
        self::assertSame(Currency::CZK, $stored->amount()->currency());
        self::assertSame('2026-11-20', $stored->firstOn()->format('Y-m-d'));
        self::assertFalse($stored->isRecurring());
        self::assertNull($stored->recurrence(new \DateTimeZone('Europe/Prague')));
        self::assertTrue($stored->isIncluded());
        self::assertSame($this->customer->id(), $stored->counterparty()?->id());
        self::assertSame($this->firm->id(), $stored->firm()->id());
        self::assertTrue($stored->matchRule()->isEmpty());
        self::assertSame('2026-11-17T10:00:00+00:00', $stored->createdAt()->format(\DATE_ATOM));
        self::assertSame([['planned_item_id' => $item->id(), 'firm_id' => $this->firm->id(), 'direction' => 'out', 'amount' => '10000.00', 'currency' => 'CZK', 'recurring' => false]], $this->events('cash-flow.planned_item.created'));
    }

    public function testCreatesARecurringItemWithItsScheduleAndMatchRule(): void
    {
        $item = $this->writes()->create(new \App\CashFlow\Application\CreatePlannedItemInput(
            $this->firm->id(), 'in', 'Retainer', 'revenue', '1500.5', '2026-11-30', 'EUR', 'month', 2, true, null, 6, null, false, ' 123-456/0100 ', '0042', '1.25',
        ));

        $stored = $this->reloaded($item);
        $recurrence = $stored->recurrence(new \DateTimeZone('Europe/Prague'));
        self::assertNotNull($recurrence);
        self::assertSame(RecurrenceUnit::Month, $recurrence->unit());
        self::assertSame(2, $recurrence->every());
        self::assertTrue($recurrence->onLastDayOfMonth());
        self::assertSame(RecurrenceEnd::AfterCount, $recurrence->end());
        self::assertSame(6, $recurrence->endAfter());
        self::assertSame('2026-11-30 Europe/Prague', $recurrence->firstIssueOn()->format('Y-m-d e'));
        self::assertTrue($stored->isRecurring());
        self::assertFalse($stored->isIncluded());
        self::assertSame(150050, $stored->amount()->minor());
        self::assertSame(Currency::EUR, $stored->amount()->currency());
        self::assertSame('123-456/0100', $stored->matchRule()->counterpartyAccount());
        self::assertSame('0042', $stored->matchRule()->variableSymbol());
        self::assertSame(125, $stored->matchRule()->toleranceMinor());
        self::assertFalse($stored->matchRule()->isEmpty());
        self::assertSame([['planned_item_id' => $item->id(), 'firm_id' => $this->firm->id(), 'direction' => 'in', 'amount' => '1500.50', 'currency' => 'EUR', 'recurring' => true]], $this->events('cash-flow.planned_item.created'));
    }

    public function testCreateRefusesWhatCannotBeUsed(): void
    {
        $this->assertRefused('name_required', 'name', fn (): PlannedItem => $this->planned(name: ' '));
        $this->assertRefused('name_required', 'name', fn (): PlannedItem => $this->planned(name: str_repeat('ř', PlannedItem::MAX_NAME + 1)));
        $this->assertRefused('category_unknown', 'category', fn (): PlannedItem => $this->planned(category: 'fun'));
        $this->assertRefused('currency_unknown', 'currency', fn (): PlannedItem => $this->planned(currency: 'GBP'));
        $this->assertRefused('amount_invalid', 'amount', fn (): PlannedItem => $this->planned(amount: '12.345'));
        $this->assertRefused('amount_invalid', 'amount', fn (): PlannedItem => $this->planned(amount: 'ten'));
        $this->assertRefused('amount_not_positive', 'amount', fn (): PlannedItem => $this->planned(amount: '0'));
        $this->assertRefused('amount_not_positive', 'amount', fn (): PlannedItem => $this->planned(amount: '-5'));
        $this->assertRefused('date_invalid', 'on', fn (): PlannedItem => $this->planned(on: '2026-02-30'));
        $this->assertRefused('date_invalid', 'on', fn (): PlannedItem => $this->planned(on: '20.11.2026'));
        $this->assertRefused('repeat_unknown', 'repeat', fn (): PlannedItem => $this->planned(repeat: 'quarter'));
        $this->assertRefused('not_recurring', 'repeat', fn (): PlannedItem => $this->planned(endsAfter: 3));
        $this->assertRefused('not_recurring', 'repeat', fn (): PlannedItem => $this->planned(endsOn: '2027-01-01'));
        $this->assertRefused('not_recurring', 'repeat', fn (): PlannedItem => $this->planned(lastDayOfMonth: true));
        $this->assertRefused('end_ambiguous', 'endsAfter', fn (): PlannedItem => $this->planned(repeat: 'month', endsOn: '2027-01-01', endsAfter: 3));
        $this->assertRefused('date_invalid', 'endsOn', fn (): PlannedItem => $this->planned(repeat: 'month', endsOn: 'soon'));
        $this->assertRefused('recurrence_invalid', 'repeat', fn (): PlannedItem => $this->planned(repeat: 'month', every: 0));
        $this->assertRefused('recurrence_invalid', 'repeat', fn (): PlannedItem => $this->planned(repeat: 'week', lastDayOfMonth: true));
        $this->assertRefused('counterparty_unknown', 'counterpartyId', fn (): PlannedItem => $this->planned(counterpartyId: '0198a4f0-8b1e-7000-8000-000000000001'));
        $this->assertRefused('direction_unknown', 'direction', fn (): PlannedItem => $this->planned(direction: 'sideways'));
        $this->assertRefused('not_found', null, fn (): PlannedItem => $this->writes()->create(new \App\CashFlow\Application\CreatePlannedItemInput('0198a4f0-8b1e-7000-8000-000000000001', 'out', 'Rent', 'rent', '1.00', '2026-11-20')));
        self::assertSame([], $this->events('cash-flow.planned_item.created'));
    }

    public function testTheMatchRuleIsChecked(): void
    {
        $this->assertRefused('match_account_invalid', 'matchAccount', fn (): PlannedItem => $this->match(str_repeat('1', PlannedItem::MAX_MATCH_ACCOUNT + 1), null, null));
        $this->assertRefused('variable_symbol_invalid', 'matchVariableSymbol', fn (): PlannedItem => $this->match(null, '12345678901', null));
        $this->assertRefused('variable_symbol_invalid', 'matchVariableSymbol', fn (): PlannedItem => $this->match(null, '12a', null));
        $this->assertRefused('tolerance_negative', 'matchTolerance', fn (): PlannedItem => $this->match(null, null, '-1.00'));
        $this->assertRefused('amount_invalid', 'matchTolerance', fn (): PlannedItem => $this->match(null, null, 'some'));

        $item = $this->match(str_repeat('1', PlannedItem::MAX_MATCH_ACCOUNT), ' ', '0');
        self::assertSame(str_repeat('1', PlannedItem::MAX_MATCH_ACCOUNT), $item->matchRule()->counterpartyAccount());
        self::assertNull($item->matchRule()->variableSymbol());
        self::assertSame(0, $item->matchRule()->toleranceMinor());
    }

    public function testChangeKeepsWhatIsLeftOutAndRecordsItsEvent(): void
    {
        $item = $this->planned(repeat: 'month', endsAfter: 12, counterpartyId: $this->customer->id());

        $this->writes()->change(new ChangePlannedItemInput($item->id(), amount: '12000.00', included: false));

        $stored = $this->reloaded($item);
        self::assertSame(1200000, $stored->amount()->minor());
        self::assertFalse($stored->isIncluded());
        self::assertSame('Office rent', $stored->name());
        self::assertSame(12, $stored->recurrence(new \DateTimeZone('Europe/Prague'))?->endAfter());
        self::assertSame($this->customer->id(), $stored->counterparty()?->id());
        self::assertSame([['planned_item_id' => $item->id(), 'firm_id' => $this->firm->id(), 'direction' => 'out', 'amount' => '12000.00', 'currency' => 'CZK', 'included' => false]], $this->events('cash-flow.planned_item.changed'));
    }

    public function testChangeReplacesOneEndWithTheOtherAndRepeatNullMakesItOneOff(): void
    {
        $item = $this->planned(on: '2026-11-30', repeat: 'month', endsAfter: 12, lastDayOfMonth: true);
        $zone = new \DateTimeZone('Europe/Prague');

        $this->writes()->change(new ChangePlannedItemInput($item->id(), endsOn: '2027-06-30'));
        $recurrence = $this->reloaded($item)->recurrence($zone) ?? throw new \LogicException('Not recurring.');
        self::assertSame(RecurrenceEnd::OnDate, $recurrence->end());
        self::assertSame('2027-06-30', $recurrence->endOn()?->format('Y-m-d'));
        self::assertNull($recurrence->endAfter());
        self::assertTrue($recurrence->onLastDayOfMonth());

        $this->writes()->change(new ChangePlannedItemInput($item->id(), endsAfter: 3));
        $recurrence = $this->reloaded($item)->recurrence($zone) ?? throw new \LogicException('Not recurring.');
        self::assertSame(RecurrenceEnd::AfterCount, $recurrence->end());
        self::assertNull($recurrence->endOn());

        $this->writes()->change(new ChangePlannedItemInput($item->id(), repeat: null));
        $stored = $this->reloaded($item);
        self::assertFalse($stored->isRecurring());
        self::assertSame('2026-11-30', $stored->firstOn()->format('Y-m-d'));

        $this->writes()->change(new ChangePlannedItemInput($item->id(), repeat: 'week', every: 2, counterpartyId: null, matchVariableSymbol: '77', matchTolerance: '5.00'));
        $stored = $this->reloaded($item);
        $recurrence = $stored->recurrence($zone) ?? throw new \LogicException('Not recurring.');
        self::assertSame(RecurrenceUnit::Week, $recurrence->unit());
        self::assertSame(2, $recurrence->every());
        self::assertSame(RecurrenceEnd::Never, $recurrence->end());
        self::assertFalse($recurrence->onLastDayOfMonth());
        self::assertSame('77', $stored->matchRule()->variableSymbol());
        self::assertSame(500, $stored->matchRule()->toleranceMinor());

        // A left-out tolerance is kept as it was, in the currency of the item.
        $this->writes()->change(new ChangePlannedItemInput($item->id(), name: 'Rent'));
        self::assertSame(500, $this->reloaded($item)->matchRule()->toleranceMinor());
        self::assertCount(5, $this->events('cash-flow.planned_item.changed'));
    }

    public function testChangeRefusesAsCreateDoesAndAnUnknownItem(): void
    {
        $item = $this->planned();

        $this->assertRefused('amount_not_positive', 'amount', fn (): PlannedItem => $this->writes()->change(new ChangePlannedItemInput($item->id(), amount: '0.00')));
        $this->assertRefused('not_found', null, fn (): PlannedItem => $this->writes()->change(new ChangePlannedItemInput('not-an-id', amount: '1.00')));
        self::assertSame(1000000, $this->reloaded($item)->amount()->minor());
        self::assertSame([], $this->events('cash-flow.planned_item.changed'));
    }

    public function testSkipsAnOccurrenceOnceAndOnlyADayTheItemFallsOn(): void
    {
        $item = $this->planned(on: '2026-11-25', repeat: 'month');

        $this->writes()->skip(new SkipPlannedOccurrenceInput($item->id(), '2027-01-25'));
        $this->writes()->skip(new SkipPlannedOccurrenceInput($item->id(), '2026-12-25'));
        $this->writes()->skip(new SkipPlannedOccurrenceInput($item->id(), '2027-01-25'));

        self::assertSame(['2026-12-25', '2027-01-25'], $this->reloaded($item)->skippedOn());
        self::assertTrue($this->reloaded($item)->isSkipped(new \DateTimeImmutable('2026-12-25')));
        self::assertFalse($this->reloaded($item)->isSkipped(new \DateTimeImmutable('2027-02-25')));
        self::assertSame(['2027-01-25', '2026-12-25', '2027-01-25'], array_column($this->events('cash-flow.planned_item.occurrence_skipped'), 'occurrence_on'));
        $this->assertRefused('not_an_occurrence', 'on', fn (): PlannedItem => $this->writes()->skip(new SkipPlannedOccurrenceInput($item->id(), '2026-12-24')));
        $this->assertRefused('not_an_occurrence', 'on', fn (): PlannedItem => $this->writes()->skip(new SkipPlannedOccurrenceInput($item->id(), '2026-10-25')));
        $this->assertRefused('date_invalid', 'on', fn (): PlannedItem => $this->writes()->skip(new SkipPlannedOccurrenceInput($item->id(), 'December')));
    }

    public function testSkipsTheDayOfAOneOffItemOnly(): void
    {
        $item = $this->planned();

        $this->assertRefused('not_an_occurrence', 'on', fn (): PlannedItem => $this->writes()->skip(new SkipPlannedOccurrenceInput($item->id(), '2026-11-21')));
        $this->writes()->skip(new SkipPlannedOccurrenceInput($item->id(), '2026-11-20'));

        self::assertSame(['2026-11-20'], $this->reloaded($item)->skippedOn());
    }

    public function testEndsARecurringItemButNotAOneOffOneOrBeforeItStarts(): void
    {
        $item = $this->planned(repeat: 'month', endsAfter: 24);

        $this->writes()->end(new EndPlannedItemInput($item->id(), '2027-03-31'));

        $recurrence = $this->reloaded($item)->recurrence(new \DateTimeZone('Europe/Prague')) ?? throw new \LogicException('Not recurring.');
        self::assertSame(RecurrenceEnd::OnDate, $recurrence->end());
        self::assertSame('2027-03-31', $recurrence->endOn()?->format('Y-m-d'));
        self::assertNull($recurrence->endAfter());
        self::assertSame([['planned_item_id' => $item->id(), 'firm_id' => $this->firm->id(), 'direction' => 'out', 'ends_on' => '2027-03-31']], $this->events('cash-flow.planned_item.ended'));
        $this->assertRefused('ends_before_start', 'on', fn (): PlannedItem => $this->writes()->end(new EndPlannedItemInput($item->id(), '2026-11-19')));
        $this->assertRefused('not_recurring', null, fn (): PlannedItem => $this->writes()->end(new EndPlannedItemInput($this->planned()->id(), '2027-03-31')), RefusalKind::Conflict);
    }

    public function testDeletesAnItemWithItsEvent(): void
    {
        $item = $this->planned();
        $id = $item->id();

        self::assertSame($id, $this->writes()->delete(new DeletePlannedItemInput($id)));

        $this->em->clear();
        self::assertNull($this->em->find(PlannedItem::class, $id));
        self::assertSame([['planned_item_id' => $id, 'firm_id' => $this->firm->id(), 'direction' => 'out']], $this->events('cash-flow.planned_item.deleted'));
        $this->assertRefused('not_found', null, fn (): string => $this->writes()->delete(new DeletePlannedItemInput($id)));
    }

    public function testReadsTheItemsOfAFirmOldestFirst(): void
    {
        $first = $this->planned(name: 'First');
        $this->clock->modify('+1 minute');
        $second = $this->planned(name: 'Second');
        $reads = self::getContainer()->get(PlannedItemReads::class);

        self::assertSame([$first->id(), $second->id()], array_map(static fn (PlannedItem $item): string => $item->id(), $reads->of($this->firm->id())));
        self::assertSame($second->id(), $reads->one($second->id())->id());
        $this->assertRefused('not_found', null, fn (): PlannedItem => $reads->one('0198a4f0-8b1e-7000-8000-000000000001'));
        $this->assertRefused('not_found', null, fn (): array => $reads->of('0198a4f0-8b1e-7000-8000-000000000001'));
    }

    public function testAGuestNeitherWritesNorReads(): void
    {
        $item = $this->planned();
        $this->signIn($this->guest);
        $reads = self::getContainer()->get(PlannedItemReads::class);

        $this->assertRefused('forbidden', null, fn (): PlannedItem => $this->planned(), RefusalKind::Forbidden);
        $this->assertRefused('forbidden', null, fn (): PlannedItem => $this->writes()->change(new ChangePlannedItemInput($item->id(), amount: '1.00')), RefusalKind::Forbidden);
        $this->assertRefused('forbidden', null, fn (): string => $this->writes()->delete(new DeletePlannedItemInput($item->id())), RefusalKind::Forbidden);
        $this->assertRefused('forbidden', null, fn (): array => $reads->of($this->firm->id()), RefusalKind::Forbidden);
        $this->assertRefused('forbidden', null, fn (): PlannedItem => $reads->one($item->id()), RefusalKind::Forbidden);
    }

    private function match(?string $account, ?string $symbol, ?string $tolerance): PlannedItem
    {
        return $this->writes()->create(new \App\CashFlow\Application\CreatePlannedItemInput($this->firm->id(), 'out', 'Insurance', 'insurance', '5005.00', '2026-11-30', matchAccount: $account, matchVariableSymbol: $symbol, matchTolerance: $tolerance));
    }

    private function reloaded(PlannedItem $item): PlannedItem
    {
        $this->em->clear();

        return $this->em->find(PlannedItem::class, $item->id()) ?? throw new \LogicException('The item was not stored.');
    }

    private function assertRefused(string $reason, ?string $field, \Closure $work, RefusalKind $kind = RefusalKind::Invalid): void
    {
        try {
            $work();
            self::fail(\sprintf('Expected the refusal %s.', $reason));
        } catch (CashFlowRefused $refused) {
            self::assertSame($reason, $refused->reason(), $refused->getMessage());
            self::assertSame($field, $refused->field());
            self::assertSame('not_found' === $reason ? RefusalKind::NotFound : $kind, $refused->kind());
            self::assertSame([], $refused->details());
        }
    }
}
