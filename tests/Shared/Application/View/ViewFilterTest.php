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
use Foresi\Shared\Application\View\InvalidFilter;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ViewFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ViewFilterTest extends TestCase
{
    public function testAStoredFilterReadsBackAsItWasWritten(): void
    {
        $data = ['match' => 'all', 'conditions' => [
            ['field' => 'state', 'op' => 'is_any_of', 'value' => ['open', 'late']],
            ['field' => 'due', 'op' => 'before', 'value' => ['relative' => 'today']],
            ['field' => 'customer', 'op' => 'is_empty'],
        ]];

        self::assertSame($data, ViewFilter::parse($data, $this->list())->toArray());
        self::assertSame([], ViewFilter::parse([], $this->list())->conditions);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalid(): iterable
    {
        yield 'not an object' => ['all', 'filters'];
        yield 'any match' => [['match' => 'any'], 'match'];
        yield 'an unknown key' => [['groups' => []], 'groups'];
        yield 'an unknown field' => [['conditions' => [['field' => 'colour', 'op' => 'is', 'value' => 'red']]], 'conditions[0].field'];
        yield 'an operator of another type' => [['conditions' => [['field' => 'state', 'op' => 'contains', 'value' => 'o']]], 'conditions[0].op'];
        yield 'an operator the field narrows away' => [['conditions' => [['field' => 'total', 'op' => 'between', 'value' => ['from' => 1, 'to' => 2]]]], 'conditions[0].op'];
        yield 'an unknown enum value' => [['conditions' => [['field' => 'state', 'op' => 'is', 'value' => 'lost']]], 'conditions[0].value'];
        yield 'an empty list' => [['conditions' => [['field' => 'state', 'op' => 'is_any_of', 'value' => []]]], 'conditions[0].value'];
        yield 'a text as a number' => [['conditions' => [['field' => 'total', 'op' => 'gt', 'value' => '5']]], 'conditions[0].value'];
        yield 'a bad relative date' => [['conditions' => [['field' => 'due', 'op' => 'is', 'value' => ['relative' => 'soon']]]], 'conditions[0].value.relative'];
        yield 'a range without its end' => [['conditions' => [['field' => 'due', 'op' => 'between', 'value' => ['from' => '2026-01-01']]]], 'conditions[0].value'];
        yield 'a value on an emptiness check' => [['conditions' => [['field' => 'customer', 'op' => 'is_empty', 'value' => 'x']]], 'conditions[0].value'];
        yield 'a boolean as a text' => [['conditions' => [['field' => 'archived', 'op' => 'is', 'value' => 'yes']]], 'conditions[0].value'];
        yield 'no operator' => [['conditions' => [['field' => 'state']]], 'conditions[0]'];
        yield 'a condition that is not an object' => [['conditions' => ['state']], 'conditions[0]'];
        yield 'conditions that are not a list' => [['conditions' => 'state'], 'conditions'];
        yield 'conditions keyed by name' => [['conditions' => ['a' => ['field' => 'archived', 'op' => 'is', 'value' => true]]], 'conditions'];
        yield 'an empty id' => [['conditions' => [['field' => 'customer', 'op' => 'is', 'value' => '']]], 'conditions[0].value'];
        yield 'a number as an id' => [['conditions' => [['field' => 'customer', 'op' => 'is', 'value' => 5]]], 'conditions[0].value'];
        yield 'a number among ids' => [['conditions' => [['field' => 'customer', 'op' => 'is_any_of', 'value' => ['a', 5]]]], 'conditions[0].value'];
        yield 'an empty text' => [['conditions' => [['field' => 'name', 'op' => 'contains', 'value' => '']]], 'conditions[0].value'];
        yield 'a number as a text' => [['conditions' => [['field' => 'name', 'op' => 'contains', 'value' => 5]]], 'conditions[0].value'];
        yield 'an unknown value among enum values' => [['conditions' => [['field' => 'state', 'op' => 'is_any_of', 'value' => ['open', 'lost']]]], 'conditions[0].value'];
        yield 'enum values keyed by name' => [['conditions' => [['field' => 'state', 'op' => 'is_none_of', 'value' => ['a' => 'open']]]], 'conditions[0].value'];
        yield 'a range of one end and another key' => [['conditions' => [['field' => 'due', 'op' => 'between', 'value' => ['to' => '2026-01-01', 'at' => '2026-01-01']]]], 'conditions[0].value'];
        yield 'a range of the other end and another key' => [['conditions' => [['field' => 'due', 'op' => 'between', 'value' => ['from' => '2026-01-01', 'at' => '2026-01-01']]]], 'conditions[0].value'];
        yield 'a range with a third key' => [['conditions' => [['field' => 'due', 'op' => 'between', 'value' => ['from' => '2026-01-01', 'to' => '2026-01-02', 'at' => '2026-01-01']]]], 'conditions[0].value'];
        yield 'a range that is a day' => [['conditions' => [['field' => 'due', 'op' => 'between', 'value' => '2026-01-01']]], 'conditions[0].value'];
        yield 'a range from no day' => [['conditions' => [['field' => 'due', 'op' => 'between', 'value' => ['from' => 'soon', 'to' => '2026-01-02']]]], 'conditions[0].value.from'];
        yield 'a range to no day' => [['conditions' => [['field' => 'due', 'op' => 'between', 'value' => ['from' => '2026-01-01', 'to' => 'later']]]], 'conditions[0].value.to'];
        yield 'a date given a range' => [['conditions' => [['field' => 'due', 'op' => 'before', 'value' => ['from' => '2026-01-01', 'to' => '2026-01-02']]]], 'conditions[0].value.relative'];
        yield 'a text as a number of a later condition' => [['conditions' => [['field' => 'archived', 'op' => 'is', 'value' => true], ['field' => 'total', 'op' => 'gt', 'value' => '5']]], 'conditions[1].value'];
    }

    #[DataProvider('invalid')]
    public function testAFilterTheListCannotApplyIsRefusedWithThePathOfItsFault(mixed $data, string $path): void
    {
        try {
            ViewFilter::parse($data, $this->list());
            self::fail('The filter is refused.');
        } catch (InvalidFilter $refused) {
            self::assertSame($path, $refused->path);
        }
    }

    public function testAViewHoldsAtMostTwentyConditions(): void
    {
        $condition = ['field' => 'archived', 'op' => 'is', 'value' => false];
        self::assertCount(20, ViewFilter::parse(['conditions' => array_fill(0, 20, $condition)], $this->list())->conditions);

        $this->expectException(InvalidFilter::class);
        ViewFilter::parse(['conditions' => array_fill(0, 21, $condition)], $this->list());
    }

    public function testAListStartsWithItsAllAndChecksTheViewsItShips(): void
    {
        $fields = [new Field('archived', FieldType::Boolean, 'Archived')];
        $list = new ListDefinition('test.items', $fields, [new DefaultView('all', 'All'), new DefaultView('archived', 'Archived', ViewFilter::all(new Condition('archived', 'is', true)))]);
        self::assertSame('test.items.archived', $list->systemKey($list->defaults[1]));

        $this->expectException(InvalidFilter::class);
        new ListDefinition('test.items', $fields, [new DefaultView('all', 'All'), new DefaultView('bad', 'Bad', ViewFilter::all(new Condition('archived', 'is', 'yes')))]);
    }

    public function testAListWithoutItsAllFirstIsAMistakeOfItsModule(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ListDefinition('test.items', [], [new DefaultView('open', 'Open')]);
    }

    public function testValidValuesOfEachTypeAreTaken(): void
    {
        $data = ['match' => 'all', 'conditions' => [
            ['field' => 'state', 'op' => 'is', 'value' => 'done'],
            ['field' => 'state', 'op' => 'is_none_of', 'value' => ['late']],
            ['field' => 'customer', 'op' => 'is', 'value' => 'c1'],
            ['field' => 'customer', 'op' => 'is_any_of', 'value' => ['c1', 'c2']],
            ['field' => 'name', 'op' => 'contains', 'value' => 'x'],
            ['field' => 'total', 'op' => 'gt', 'value' => 5],
            ['field' => 'total', 'op' => 'lt', 'value' => 5.5],
            ['field' => 'due', 'op' => 'between', 'value' => ['from' => '2026-01-01', 'to' => ['relative' => 'today']]],
            ['field' => 'due', 'op' => 'is', 'value' => ['month' => '2026-10']],
            ['field' => 'archived', 'op' => 'is', 'value' => false],
        ]];

        self::assertSame($data, ViewFilter::parse($data, $this->list())->toArray());
    }

    public function testAConditionIsAnObject(): void
    {
        $this->expectExceptionMessageIsOrContains('conditions[0]: A condition is an object.');
        ViewFilter::parse(['conditions' => ['state']], $this->list());
    }

    public function testAnEnumRefusalNamesTheValuesItTakes(): void
    {
        foreach ([['is', 'lost', 'Expected one of open, late, done.'], ['is_any_of', ['lost'], 'Expected a list of values of open, late, done.']] as [$op, $value, $message]) {
            try {
                ViewFilter::parse(['conditions' => [['field' => 'state', 'op' => $op, 'value' => $value]]], $this->list());
                self::fail('The value is refused.');
            } catch (InvalidFilter $refused) {
                self::assertSame('conditions[0].value: '.$message, $refused->getMessage());
            }
        }
    }

    public function testTheConditionsOnAFieldAreAList(): void
    {
        $due = new Condition('due', 'is', '2026-01-01');
        $filter = ViewFilter::all(new Condition('archived', 'is', true), $due);

        self::assertSame([$due], $filter->on('due'));
        self::assertSame([], $filter->on('state'));
        self::assertSame([$due, $due], $filter->with($due)->on('due'));
        self::assertSame([$due], ViewFilter::all(...['due' => $due])->conditions);
    }

    public function testAListKeyIsAModuleAndAList(): void
    {
        foreach (['items', 'Test.items', 'xtest.items-', '1test.items', 'test.items.', '-test.items', 'test.items x'] as $key) {
            try {
                new ListDefinition($key, [], [new DefaultView('all', 'All')]);
                self::fail(\sprintf('The key "%s" is refused.', $key));
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame('test.my_items.all', new ListDefinition('test.my_items', [], [new DefaultView('all', 'All')])->systemKey(new DefaultView('all', 'All')));
    }

    public function testTheDefaultViewsOfAListHaveOneSlugEach(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ListDefinition('test.items', [], [new DefaultView('all', 'All'), new DefaultView('open', 'Open'), new DefaultView('open', 'Open again')]);
    }

    public function testASlugIsLowercaseLettersDigitsAndUnderscores(): void
    {
        foreach (['1paid', 'paid-x', 'Paid', '_paid', 'paid now'] as $slug) {
            try {
                new DefaultView($slug, 'Paid');
                self::fail(\sprintf('The slug "%s" is refused.', $slug));
            } catch (\InvalidArgumentException) {
            }
        }
        $view = new DefaultView('to_refund2', 'To refund');
        self::assertSame(0, $view->position);
        self::assertTrue($view->showCount);
    }

    public function testOnlyAnEnumFieldNeedsItsValues(): void
    {
        self::assertSame(['x'], (new Field('name', FieldType::Text, 'Name', options: ['x']))->options);

        $this->expectException(\InvalidArgumentException::class);
        new Field('state', FieldType::Enum, 'State');
    }

    public function testARefusalSaysWhereAndWhy(): void
    {
        $refused = new InvalidFilter('conditions[1].op', 'Unknown.');

        self::assertSame('conditions[1].op: Unknown.', $refused->getMessage());
    }

    private function list(): ListDefinition
    {
        return new ListDefinition('test.items', [
            new Field('state', FieldType::Enum, 'State', options: ['open', 'late', 'done']),
            new Field('due', FieldType::Date, 'Due'),
            new Field('customer', FieldType::Reference, 'Customer'),
            new Field('total', FieldType::Number, 'Total', operators: ['eq', 'gt', 'lt']),
            new Field('name', FieldType::Text, 'Name'),
            new Field('archived', FieldType::Boolean, 'Archived'),
        ], [new DefaultView('all', 'All')]);
    }
}
