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
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\RowConditions;
use Foresi\Shared\Application\View\ViewFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RowConditionsTest extends TestCase
{
    /** Rows by name: a customer, a state, a reference, an amount and a flag; "empty" holds nothing. */
    private const array ROWS = [
        'acme' => ['customer' => 'Šťastná Acme s.r.o.', 'state' => 'open', 'contact' => 'c1', 'amount' => 100.5, 'late' => true],
        'beta' => ['customer' => 'Beta Example a.s.', 'state' => 'done', 'contact' => 'c2', 'amount' => 2000, 'late' => false],
        'empty' => ['customer' => '', 'state' => null, 'contact' => null, 'amount' => null, 'late' => false],
    ];
    /** The deadline of each row, apart: a day is a DateTimeImmutable. */
    private const array DEADLINES = ['acme' => '2026-11-10', 'beta' => '2026-11-25', 'empty' => null];

    /**
     * @return iterable<string, array{Condition, list<string>}>
     */
    public static function conditions(): iterable
    {
        yield 'enum is' => [new Condition('state', 'is', 'open'), ['acme']];
        yield 'enum is not keeps the empty one' => [new Condition('state', 'is_not', 'open'), ['beta', 'empty']];
        yield 'enum is any of' => [new Condition('state', 'is_any_of', ['open', 'done']), ['acme', 'beta']];
        yield 'enum is none of keeps the empty one' => [new Condition('state', 'is_none_of', ['done']), ['acme', 'empty']];
        yield 'reference is' => [new Condition('contact', 'is', 'c2'), ['beta']];
        yield 'reference is empty' => [new Condition('contact', 'is_empty'), ['empty']];
        yield 'reference is not empty' => [new Condition('contact', 'is_not_empty'), ['acme', 'beta']];
        yield 'boolean true' => [new Condition('late', 'is', true), ['acme']];
        yield 'boolean false' => [new Condition('late', 'is', false), ['beta', 'empty']];
        yield 'text contains, whatever the case' => [new Condition('customer', 'contains', 'ACME'), ['acme']];
        yield 'text contains, whatever the case of a letter with a mark' => [new Condition('customer', 'contains', 'ŠŤASTNÁ'), ['acme']];
        yield 'text contains a word with a mark in lower case' => [new Condition('customer', 'contains', 'šťastná'), ['acme']];
        yield 'text does not contain keeps the empty one' => [new Condition('customer', 'does_not_contain', 'acme'), ['beta', 'empty']];
        yield 'text is' => [new Condition('customer', 'is', 'Beta Example a.s.'), ['beta']];
        yield 'text is empty counts the empty string' => [new Condition('customer', 'is_empty'), ['empty']];
        yield 'number equals' => [new Condition('amount', 'eq', 2000), ['beta']];
        yield 'number differs, never the empty one' => [new Condition('amount', 'ne', 2000), ['acme']];
        yield 'number below' => [new Condition('amount', 'lt', 2000), ['acme']];
        yield 'number at most' => [new Condition('amount', 'le', 2000), ['acme', 'beta']];
        yield 'number above' => [new Condition('amount', 'gt', 100.5), ['beta']];
        yield 'number at least' => [new Condition('amount', 'ge', 100.5), ['acme', 'beta']];
        yield 'number between, both ends in' => [new Condition('amount', 'between', ['from' => 100.5, 'to' => 2000]), ['acme', 'beta']];
        yield 'number between, an end out' => [new Condition('amount', 'between', ['from' => 101, 'to' => 1999]), []];
        yield 'date is a day' => [new Condition('deadline', 'is', '2026-11-10'), ['acme']];
        yield 'date is a month' => [new Condition('deadline', 'is', ['month' => '2026-11']), ['acme', 'beta']];
        yield 'date before the period starts' => [new Condition('deadline', 'before', ['relative' => 'today']), ['acme']];
        yield 'date on or after' => [new Condition('deadline', 'on_or_after', '2026-11-25'), ['beta']];
        yield 'date after the period ends' => [new Condition('deadline', 'after', '2026-11-10'), ['beta']];
        yield 'date on or before' => [new Condition('deadline', 'on_or_before', '2026-11-10'), ['acme']];
        yield 'date before a day leaves the day out' => [new Condition('deadline', 'before', '2026-11-10'), []];
        yield 'date after a day, the next day in' => [new Condition('deadline', 'after', '2026-11-24'), ['beta']];
        yield 'date on or before a day, the next day out' => [new Condition('deadline', 'on_or_before', '2026-11-24'), ['acme']];
        yield 'date between days' => [new Condition('deadline', 'between', ['from' => '2026-11-10', 'to' => '2026-11-24']), ['acme']];
        yield 'date between, the last day in' => [new Condition('deadline', 'between', ['from' => '2026-11-11', 'to' => '2026-11-25']), ['beta']];
        yield 'date is empty' => [new Condition('deadline', 'is_empty'), ['empty']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('conditions')]
    public function testEachOperatorKeepsTheRowsItMatches(Condition $condition, array $expected): void
    {
        self::assertSame($expected, $this->kept(ViewFilter::all($condition)));
    }

    public function testAllConditionsMustMatch(): void
    {
        self::assertSame(['acme'], $this->kept(ViewFilter::all(new Condition('amount', 'lt', 5000), new Condition('late', 'is', true))));
        self::assertSame([], $this->kept(ViewFilter::all(new Condition('state', 'is', 'done'), new Condition('late', 'is', true))));
    }

    public function testNoConditionKeepsEveryRowInItsOrder(): void
    {
        self::assertSame(['acme', 'beta', 'empty'], $this->kept(new ViewFilter()));
    }

    /**
     * @return iterable<string, array{Condition}>
     */
    public static function operatorsOfAnotherType(): iterable
    {
        yield 'text' => [new Condition('customer', 'lt', 'a')];
        yield 'number' => [new Condition('amount', 'contains', 1)];
        yield 'date' => [new Condition('deadline', 'contains', '2026-11-10')];
    }

    /**
     * The list checks a condition before it is matched; one it would refuse is a mistake in the code.
     */
    #[DataProvider('operatorsOfAnotherType')]
    public function testAnOperatorOfAnotherTypeIsAnError(Condition $condition): void
    {
        $this->expectException(\LogicException::class);

        $this->kept(ViewFilter::all($condition));
    }

    public function testAFieldTheListLacksIsAnError(): void
    {
        $this->expectException(\LogicException::class);

        $this->kept(ViewFilter::all(new Condition('missing', 'is', true)));
    }

    /**
     * @return list<string>
     */
    private function kept(ViewFilter $filter): array
    {
        $list = new ListDefinition('test.rows', [
            new Field('customer', FieldType::Text, 'customer'),
            new Field('state', FieldType::Enum, 'state', options: ['open', 'done']),
            new Field('contact', FieldType::Reference, 'contact'),
            new Field('amount', FieldType::Number, 'amount'),
            new Field('deadline', FieldType::Date, 'deadline'),
            new Field('late', FieldType::Boolean, 'late'),
        ], [new DefaultView(ListDefinition::ALL, 'all')]);
        $today = new \DateTimeImmutable('2026-11-18', new \DateTimeZone('Europe/Prague'));
        $value = static function (string $name, string $field): mixed {
            if ('deadline' === $field) {
                $day = self::DEADLINES[$name];

                return null === $day ? null : new \DateTimeImmutable($day);
            }

            return self::ROWS[$name][$field];
        };

        return RowConditions::filter(array_keys(self::ROWS), $filter, $list, $value, $today);
    }
}
