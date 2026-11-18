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
