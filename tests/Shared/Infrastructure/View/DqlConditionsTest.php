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
        yield 'date is not empty' => ['due', 'is_not_empty', null, 'x.due IS NOT NULL', []];
        yield 'number is not empty' => ['total', 'is_not_empty', null, 'x.total IS NOT NULL', []];
        yield 'text is not keeps the empty' => ['name', 'is_not', 'Acme', '(x.name IS NULL OR x.name <> :v_0)', ['v_0' => ['Acme', null]]];
        yield 'text is not empty' => ['name', 'is_not_empty', null, '(x.name IS NOT NULL AND x.name <> \'\')', []];
        yield 'an expression of the field' => ['city', 'is', 'Brno', 'x.city = :v_0', ['v_0' => ['Brno', null]]];
        yield 'a set has any of' => ['tags', 'has_any_of', ['a', 'b'], '(CAST_TEXT(x.tags) LIKE :v_0_0 ESCAPE \'!\' OR CAST_TEXT(x.tags) LIKE :v_0_1 ESCAPE \'!\')', ['v_0_0' => ['%"a"%', null], 'v_0_1' => ['%"b"%', null]]];
        yield 'a set has all of' => ['tags', 'has_all_of', ['a', 'b'], '(CAST_TEXT(x.tags) LIKE :v_0_0 ESCAPE \'!\' AND CAST_TEXT(x.tags) LIKE :v_0_1 ESCAPE \'!\')', ['v_0_0' => ['%"a"%', null], 'v_0_1' => ['%"b"%', null]]];
        yield 'a set has none of keeps the blank' => ['tags', 'has_none_of', ['a'], '(x.tags IS NULL OR NOT (CAST_TEXT(x.tags) LIKE :v_0_0 ESCAPE \'!\'))', ['v_0_0' => ['%"a"%', null]]];
        yield 'a set is empty' => ['tags', 'is_empty', null, '(x.tags IS NULL OR CAST_TEXT(x.tags) = \'[]\')', []];
        yield 'a set is not empty' => ['tags', 'is_not_empty', null, 'NOT (x.tags IS NULL OR CAST_TEXT(x.tags) = \'[]\')', []];
        yield 'a related row matches' => ['email', 'contains', 'acme', 'EXISTS (SELECT 1 FROM App\\Email e_v_0 WHERE e_v_0.owner = x AND LOWER(e_v_0.address) LIKE :v_0 ESCAPE \'!\')', ['v_0' => ['%acme%', null]]];
        yield 'no related row matches' => ['email', 'does_not_contain', 'acme', 'NOT EXISTS (SELECT 1 FROM App\\Email e_v_0 WHERE e_v_0.owner = x AND LOWER(e_v_0.address) LIKE :v_0 ESCAPE \'!\')', ['v_0' => ['%acme%', null]]];
        yield 'the related row is the alias itself' => ['invoice', 'is', 'A1', 'EXISTS (SELECT 1 FROM App\\Invoice si_v_0 WHERE si_v_0 = x.invoice AND si_v_0.number = :v_0)', ['v_0' => ['A1', null]]];
        yield 'no related row has a value' => ['email', 'is_empty', null, 'NOT EXISTS (SELECT 1 FROM App\\Email e_v_0 WHERE e_v_0.owner = x AND (e_v_0.address IS NOT NULL AND e_v_0.address <> \'\'))', []];
        yield 'an amount in whole units against minor ones' => ['price', 'ge', 12.5, 'x.priceMinor >= :v_0', ['v_0' => [1250, null]]];
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
        $compiler = new DqlConditions($this->list(), self::COLUMNS, static fn (Condition $condition, string $name): array => [DqlConditions::derivedEnum($condition, ['open' => 'o'.$name, 'late' => 'l'.$name]), ['shared' => ['x', null]]]);

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
        $compiler = new DqlConditions($this->list(), self::COLUMNS, static fn (): never => throw new \LogicException('Every field here is stored.'));
        $filter = ViewFilter::all($condition);
        $filter->check($this->list());

        return $compiler->compile($filter, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-18 00:00', new \DateTimeZone('Europe/Prague')), 'v');
    }

    private function list(): ListDefinition
    {
        return new ListDefinition('test.items', [
            new Field('state', FieldType::Enum, 'State', options: ['open', 'late', 'done']),
            new Field('stage', FieldType::Enum, 'Stage', options: ['open', 'late']),
            new Field('customer', FieldType::Reference, 'Customer'),
            new Field('name', FieldType::Text, 'Name'),
            new Field('total', FieldType::Number, 'Total'),
            new Field('archived', FieldType::Boolean, 'Archived'),
            new Field('due', FieldType::Date, 'Due'),
            new Field('city', FieldType::Text, 'City', expression: 'x.city'),
            new Field('tags', FieldType::MultiEnum, 'Tags', options: ['a', 'b'], expression: 'x.tags'),
            new Field('email', FieldType::Text, 'E-mail', expression: 'e.address', within: 'App\\Email e WHERE e.owner = x'),
            new Field('invoice', FieldType::Text, 'Invoice', expression: 'si.number', within: 'App\\Invoice si WHERE si = x.invoice'),
            new Field('price', FieldType::Number, 'Price', expression: 'x.priceMinor', scale: 100),
        ], [new DefaultView('all', 'All')]);
    }
}
