<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\View;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Infrastructure\View\DqlConditions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DqlConditionsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, mixed, string, array<string, mixed>}>
     */
    public static function conditions(): iterable
    {
        yield 'enum is' => ['state', 'is', 'open', 'x.state = :v_0', ['v_0' => ['open', null]]];
        yield 'enum is not keeps the empty' => ['state', 'is_not', 'open', '(x.state <> :v_0 OR x.state IS NULL)', ['v_0' => ['open', null]]];
        yield 'enum is any of' => ['state', 'is_any_of', ['open', 'late'], 'x.state IN (:v_0)', ['v_0' => [['open', 'late'], ArrayParameterType::STRING]]];
        yield 'enum is none of keeps the empty' => ['state', 'is_none_of', ['done'], '(x.state NOT IN (:v_0) OR x.state IS NULL)', ['v_0' => [['done'], ArrayParameterType::STRING]]];
        yield 'reference is empty' => ['customer', 'is_empty', null, 'x.customerId IS NULL', []];
        yield 'reference is not empty' => ['customer', 'is_not_empty', null, 'x.customerId IS NOT NULL', []];
        yield 'reference is' => ['customer', 'is', 'c1', 'x.customerId = :v_0', ['v_0' => ['c1', null]]];
        yield 'text contains, escaped' => ['name', 'contains', '50%_Off!', 'LOWER(x.name) LIKE :v_0 ESCAPE \'!\'', ['v_0' => ['%50!%!_off!!%', null]]];
        yield 'text does not contain keeps the empty' => ['name', 'does_not_contain', 'a', '(x.name IS NULL OR LOWER(x.name) NOT LIKE :v_0 ESCAPE \'!\')', ['v_0' => ['%a%', null]]];
        yield 'text is' => ['name', 'is', 'Acme', 'x.name = :v_0', ['v_0' => ['Acme', null]]];
        yield 'text is empty' => ['name', 'is_empty', null, '(x.name IS NULL OR x.name = \'\')', []];
        yield 'number at least' => ['total', 'ge', 100, 'x.total >= :v_0', ['v_0' => [100, null]]];
        yield 'number not' => ['total', 'ne', 5, 'x.total <> :v_0', ['v_0' => [5, null]]];
        yield 'number between, inclusive' => ['total', 'between', ['from' => 1, 'to' => 9], 'x.total >= :v_0_from AND x.total <= :v_0_to', ['v_0_from' => [1, null], 'v_0_to' => [9, null]]];
        yield 'boolean' => ['archived', 'is', true, 'x.archived = :v_0', ['v_0' => [true, Types::BOOLEAN]]];
        yield 'date is empty' => ['due', 'is_empty', null, 'x.due IS NULL', []];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    #[DataProvider('conditions')]
    public function testEachOperatorCompilesToItsPredicate(string $field, string $op, mixed $value, string $dql, array $parameters): void
    {
        self::assertSame(['('.$dql.')', $parameters], $this->compile(new Condition($field, $op, $value)));
    }

    /**
     * @return iterable<string, array{string, mixed, string, array<string, string>}>
     */
    public static function dates(): iterable
    {
        // Today is Wednesday 2026-11-18; last month is October.
        $month = ['relative' => 'last_month'];
        yield 'is a period: within it' => ['is', $month, 'x.due >= :v_0_start AND x.due < :v_0_end', ['v_0_start' => '2026-10-01', 'v_0_end' => '2026-11-01']];
        yield 'before: before it starts' => ['before', ['relative' => 'today'], 'x.due < :v_0_start', ['v_0_start' => '2026-11-18']];
        yield 'after: after it ends' => ['after', $month, 'x.due >= :v_0_end', ['v_0_end' => '2026-11-01']];
        yield 'on or before: up to its end' => ['on_or_before', '2026-11-05', 'x.due < :v_0_end', ['v_0_end' => '2026-11-06']];
        yield 'on or after: from its start' => ['on_or_after', ['month' => '2026-09'], 'x.due >= :v_0_start', ['v_0_start' => '2026-09-01']];
        yield 'between: from the start of one to the end of the other' => ['between', ['from' => ['relative' => 'last_week'], 'to' => ['relative' => 'today']], 'x.due >= :v_0_start AND x.due < :v_0_end', ['v_0_start' => '2026-11-09', 'v_0_end' => '2026-11-19']];
        yield 'overdue more than 14 days' => ['before', ['relative' => 'n_days_ago', 'n' => 14], 'x.due < :v_0_start', ['v_0_start' => '2026-11-04']];
    }

    /**
     * @param array<string, string> $days
     */
    #[DataProvider('dates')]
    public function testADateResolvesAgainstTheInstancesToday(string $op, mixed $value, string $dql, array $days): void
    {
        [$predicate, $parameters] = $this->compile(new Condition('due', $op, $value));

        self::assertSame('('.$dql.')', $predicate);
        self::assertSame($days, array_map(static fn (array $parameter): string => $parameter[0] instanceof \DateTimeImmutable ? $parameter[0]->format('Y-m-d') : 'not a day', $parameters));
        self::assertSame([Types::DATE_IMMUTABLE], array_values(array_unique(array_map(static fn (array $parameter): ?string => \is_string($parameter[1]) ? $parameter[1] : null, $parameters))));
    }

    public function testConditionsJoinWithAndAndADerivedFieldIsTheModulesSql(): void
    {
        $compiler = new DqlConditions(self::list(), self::COLUMNS, static fn (Condition $condition, string $name): array => [DqlConditions::derivedEnum($condition, ['open' => 'o'.$name, 'late' => 'l'.$name]), ['shared' => ['x', null]]]);

        [$dql, $parameters] = $compiler->compile(ViewFilter::all(new Condition('archived', 'is', false), new Condition('stage', 'is_none_of', ['open', 'late'])), static fn (): \DateTimeImmutable => throw new \LogicException('No date asks for today.'), 'v');

        self::assertSame('(x.archived = :v_0) AND (NOT (ov_1 OR lv_1))', $dql);
        self::assertSame(['v_0' => [false, Types::BOOLEAN], 'shared' => ['x', null]], $parameters);
        self::assertSame(['1 = 1', []], $compiler->compile(new ViewFilter(), static fn (): \DateTimeImmutable => new \DateTimeImmutable(), 'v'));
        self::assertSame('NOT (paid)', DqlConditions::derivedBoolean(new Condition('archived', 'is', false), 'paid'));
    }

    private const array COLUMNS = ['state' => 'x.state', 'customer' => 'x.customerId', 'name' => 'x.name', 'total' => 'x.total', 'archived' => 'x.archived', 'due' => 'x.due'];

    /**
     * @return array{string, array<string, array{mixed, string|ArrayParameterType|null}>}
     */
    private function compile(Condition $condition): array
    {
        $compiler = new DqlConditions(self::list(), self::COLUMNS, static fn (): never => throw new \LogicException('Every field here is stored.'));
        $filter = ViewFilter::all($condition);
        $filter->check(self::list());

        return $compiler->compile($filter, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-18 00:00', new \DateTimeZone('Europe/Prague')), 'v');
    }

    private static function list(): ListDefinition
    {
        return new ListDefinition('test.items', [
            new Field('state', FieldType::Enum, 'State', options: ['open', 'late', 'done']),
            new Field('stage', FieldType::Enum, 'Stage', options: ['open', 'late']),
            new Field('customer', FieldType::Reference, 'Customer'),
            new Field('name', FieldType::Text, 'Name'),
            new Field('total', FieldType::Number, 'Total'),
            new Field('archived', FieldType::Boolean, 'Archived'),
            new Field('due', FieldType::Date, 'Due'),
        ], [new DefaultView('all', 'All')]);
    }
}
