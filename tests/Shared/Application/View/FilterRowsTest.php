<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Application\View;

use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\FilterRows;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use PHPUnit\Framework\TestCase;

final class FilterRowsTest extends TestCase
{
    public function testEachTypeReadsFromItsControls(): void
    {
        $filter = FilterRows::parse([
            ['field' => 'state', 'op' => 'is', 'values' => ['late', 'open']],
            ['field' => 'state', 'op' => 'is_not', 'values' => ['open']],
            ['field' => 'late', 'op' => 'is', 'value' => '0'],
            ['field' => 'customer', 'op' => 'is', 'value' => 'c-1', 'text' => 'Acme'],
            ['field' => 'customer', 'op' => 'is_empty', 'value' => 'ignored'],
            ['field' => 'amount', 'op' => 'between', 'from' => '1 250,50', 'to' => '2000'],
            ['field' => 'due', 'op' => 'before', 'from' => ['rule' => 'today', 'day' => '2026-01-01']],
            ['field' => 'due', 'op' => 'is', 'from' => ['rule' => 'n_days_ago', 'n' => '14']],
            ['field' => 'due', 'op' => 'between', 'from' => ['rule' => 'day', 'day' => '2026-10-01'], 'to' => ['rule' => 'month', 'month' => '2026-11']],
        ], $this->list());

        self::assertSame([
            // Ticked in another order, read in the field's: the same choice is the same filter.
            ['field' => 'state', 'op' => 'is_any_of', 'value' => ['open', 'late']],
            ['field' => 'state', 'op' => 'is_not', 'value' => 'open'],
            ['field' => 'late', 'op' => 'is', 'value' => false],
            ['field' => 'customer', 'op' => 'is', 'value' => 'c-1'],
            ['field' => 'customer', 'op' => 'is_empty'],
            ['field' => 'amount', 'op' => 'between', 'value' => ['from' => 1250.5, 'to' => 2000]],
            ['field' => 'due', 'op' => 'before', 'value' => ['relative' => 'today']],
            ['field' => 'due', 'op' => 'is', 'value' => ['relative' => 'n_days_ago', 'n' => 14]],
            ['field' => 'due', 'op' => 'between', 'value' => ['from' => '2026-10-01', 'to' => ['month' => '2026-11']]],
        ], $filter->toArray()['conditions']);
    }

    public function testARowItCannotReadIsDroppedAndTheOthersKept(): void
    {
        $filter = FilterRows::parse([
            ['field' => 'state', 'op' => '', 'values' => ['open']],
            ['field' => 'state', 'op' => 'is', 'values' => []],
            ['field' => 'state', 'op' => 'is', 'values' => ['unknown']],
            ['field' => 'gone', 'op' => 'is', 'value' => 'x'],
            ['field' => 'amount', 'op' => 'gt', 'from' => 'a lot'],
            ['field' => 'amount', 'op' => 'between', 'from' => '5'],
            ['field' => 'due', 'op' => 'is', 'from' => ['rule' => 'last_n_days', 'n' => '999']],
            ['field' => 'due', 'op' => 'is', 'from' => ['rule' => 'day', 'day' => '2026-02-30']],
            ['field' => 'late', 'op' => 'is', 'value' => ''],
            'not a row',
            ['field' => 'amount', 'op' => 'ge', 'from' => '10'],
        ], $this->list());

        self::assertSame([['field' => 'amount', 'op' => 'ge', 'value' => 10]], $filter->toArray()['conditions']);
    }

    public function testARowSendsItsConditionBackUnchanged(): void
    {
        $conditions = [
            new Condition('state', 'is_any_of', ['open', 'late']),
            new Condition('state', 'is_none_of', ['open', 'late']),
            new Condition('late', 'is', true),
            new Condition('customer', 'is_not', 'c-1'),
            new Condition('amount', 'between', ['from' => 1.5, 'to' => 3]),
            new Condition('due', 'on_or_after', ['relative' => 'next_n_days', 'n' => 7]),
            new Condition('due', 'between', ['from' => '2026-10-01', 'to' => ['month' => '2026-11']]),
            new Condition('due', 'is_empty'),
        ];
        $list = $this->list();

        $rows = array_map(static fn (Condition $condition): array => FilterRows::row($condition, $list->fields[$condition->field]), $conditions);

        self::assertSame(new ViewFilter($conditions)->toArray(), FilterRows::parse($rows, $list)->toArray());
    }

    public function testAConditionTheBuilderCannotShowIsSentBackWhole(): void
    {
        $condition = new Condition('customer', 'is_any_of', ['c-1', 'c-2']);

        self::assertFalse(FilterRows::editable($condition, $this->list()->fields['customer']));
        self::assertSame([$condition->toArray()], FilterRows::parse([[FilterRows::RAW => json_encode($condition->toArray())]], $this->list())->toArray()['conditions']);
        self::assertSame([], FilterRows::parse([[FilterRows::RAW => '{"field":"gone","op":"is"}'], [FilterRows::RAW => '{']], $this->list())->conditions);
    }

    public function testTheBuilderOffersEveryOperatorOfAnEnumAndOneReferenceAtATime(): void
    {
        $fields = $this->list()->fields;

        self::assertSame(['is', 'is_not', 'is_any_of', 'is_none_of'], FilterRows::operators($fields['state']));
        self::assertSame(['is', 'is_not', 'is_empty', 'is_not_empty'], FilterRows::operators($fields['customer']));
        self::assertSame(['is_any_of'], FilterRows::operators(new Field('only', FieldType::Enum, 'Only', ['is_any_of'], ['a'])));
        self::assertSame(['contains', 'does_not_contain', 'is', 'is_not', 'is_empty', 'is_not_empty'], FilterRows::operators($fields['note']));
        self::assertSame(['has_any_of', 'has_all_of', 'has_none_of', 'is_empty', 'is_not_empty'], FilterRows::operators($fields['tags']));
    }

    public function testAWorkingFilterRoundTripsThroughTheAddressAndIsLeftOutWhenItIsTheViews(): void
    {
        $list = $this->list();
        $filter = ViewFilter::all(new Condition('due', 'before', ['relative' => 'today']), new Condition('amount', 'ge', 1.0));

        $param = WorkingFilter::encode($filter);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $param);
        self::assertSame($filter->toArray(), WorkingFilter::decode($param, $list)?->toArray());
        self::assertNull(WorkingFilter::param($filter, $filter));
        self::assertSame($param, WorkingFilter::param($filter, new ViewFilter()));
        self::assertNull(WorkingFilter::decode('', $list));
        self::assertNull(WorkingFilter::decode('not*base64', $list));
        self::assertNull(WorkingFilter::decode(WorkingFilter::encode(ViewFilter::all(new Condition('due', 'before', 'tomorrow'))), $list));
    }

    public function testAFormKeepsAtMostTheConditionsAViewHolds(): void
    {
        $rows = array_fill(0, ViewFilter::MAX_CONDITIONS + 1, ['field' => 'amount', 'op' => 'ge', 'from' => '1']);

        self::assertCount(ViewFilter::MAX_CONDITIONS, FilterRows::parse($rows, $this->list())->conditions);
    }

    public function testATextAndAReferenceAreReadTrimmedAndSentBack(): void
    {
        $filter = FilterRows::parse([
            ['field' => 'note', 'op' => 'contains', 'value' => 'Česko'],
            ['field' => 'customer', 'op' => 'is', 'value' => ' c-1 '],
            ['field' => 'state', 'op' => 'is', 'values' => ['late']],
        ], $this->list());
        $list = $this->list();

        self::assertSame([
            ['field' => 'note', 'op' => 'contains', 'value' => 'Česko'],
            ['field' => 'customer', 'op' => 'is', 'value' => 'c-1'],
            ['field' => 'state', 'op' => 'is', 'value' => 'late'],
        ], $filter->toArray()['conditions']);
        self::assertSame('Česko', FilterRows::row($filter->conditions[0], $list->fields['note'])['value']);
        self::assertSame(['late'], FilterRows::row($filter->conditions[2], $list->fields['state'])['values']);
        $next = FilterRows::row(new Condition('due', 'is', ['relative' => 'next_n_days', 'n' => 7]), $list->fields['due'])['from'];
        self::assertIsArray($next);
        self::assertSame('7', $next['n']);
        self::assertTrue(FilterRows::editable($filter->conditions[1], $list->fields['customer']));
        self::assertTrue(FilterRows::editable($filter->conditions[0], $list->fields['note']));
        self::assertFalse(FilterRows::editable(new Condition('customer', 'is_any_of', ['c-1', 'c-2']), $list->fields['customer']));
        // The address keeps the text as it is written.
        $json = base64_decode(strtr(WorkingFilter::encode($filter), '-_', '+/'), true);
        self::assertIsString($json);
        self::assertStringContainsString('Česko', $json);
    }

    public function testABlankRowNamesItsFieldWithNothingSet(): void
    {
        $blank = FilterRows::blank($this->list()->fields['due']);

        self::assertSame(['field' => 'due', 'op' => '', 'values' => [], 'value' => ''], array_slice($blank, 0, 4));
        self::assertIsArray($blank['from']);
        self::assertSame('today', $blank['from']['rule']);
        self::assertSame('', FilterRows::blank($this->list()->fields['amount'])['from']);
    }

    public function testARawRowNeedsAFieldAndAnOperator(): void
    {
        $list = $this->list();

        self::assertSame([], FilterRows::parse([[FilterRows::RAW => '"text"'], [FilterRows::RAW => '{"op":"is_empty"}'], [FilterRows::RAW => '{"field":"customer"}']], $list)->conditions);
        self::assertCount(1, FilterRows::parse([[FilterRows::RAW => '{"field":"customer","op":"is_empty"}']], $list)->conditions);
    }

    public function testAnEnumIsAnyOfKeepsItsListAndIsWithSeveralTickedIsAnyOf(): void
    {
        $filter = FilterRows::parse([
            ['field' => 'state', 'op' => 'is_any_of', 'values' => ['late']],
            ['field' => 'state', 'op' => 'is', 'values' => ['late', 'open']],
            ['field' => 'state', 'op' => 'is_none_of', 'values' => ['open']],
        ], $this->list());

        self::assertSame([
            ['field' => 'state', 'op' => 'is_any_of', 'value' => ['late']],
            ['field' => 'state', 'op' => 'is_any_of', 'value' => ['open', 'late']],
            ['field' => 'state', 'op' => 'is_none_of', 'value' => ['open']],
        ], $filter->toArray()['conditions']);
        self::assertSame('is_none_of', FilterRows::row($filter->conditions[2], $this->list()->fields['state'])['op']);
    }

    public function testASetIsTickedWithItsOperatorAndATextTakesNoValueWhenItChecksEmptiness(): void
    {
        $list = $this->list();
        $filter = FilterRows::parse([
            ['field' => 'tags', 'op' => 'has_all_of', 'values' => ['b', 'a', 'x']],
            ['field' => 'tags', 'op' => 'has_none_of', 'values' => []],
            ['field' => 'tags', 'op' => 'is_empty'],
            ['field' => 'note', 'op' => 'is_not_empty', 'value' => 'ignored'],
            ['field' => 'note', 'op' => 'is_not', 'value' => ' x '],
        ], $list);

        self::assertSame([
            ['field' => 'tags', 'op' => 'has_all_of', 'value' => ['a', 'b']],
            ['field' => 'tags', 'op' => 'is_empty'],
            ['field' => 'note', 'op' => 'is_not_empty'],
            ['field' => 'note', 'op' => 'is_not', 'value' => 'x'],
        ], $filter->toArray()['conditions']);
        $row = FilterRows::row($filter->conditions[0], $list->fields['tags']);
        self::assertSame(['has_all_of', ['a', 'b']], [$row['op'], $row['values']]);
    }

    private function list(): ListDefinition
    {
        return new ListDefinition('test.items', [
            new Field('state', FieldType::Enum, 'State', options: ['open', 'late']),
            new Field('late', FieldType::Boolean, 'Late'),
            new Field('customer', FieldType::Reference, 'Customer', source: 'contact'),
            new Field('amount', FieldType::Number, 'Amount'),
            new Field('due', FieldType::Date, 'Due'),
            new Field('note', FieldType::Text, 'Note'),
            new Field('tags', FieldType::MultiEnum, 'Tags', options: ['a', 'b']),
        ], [new DefaultView('all', 'All', new ViewFilter())]);
    }
}
