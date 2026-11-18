<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\Query;
use Foresi\Banking\Application\View\BankMovementLists;
use Foresi\Banking\Contract\MovementDirection;
use Foresi\Banking\Domain\MovementState;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Infrastructure\View\DqlConditions;
use Psr\Clock\ClockInterface;

/**
 * The conditions of a view of the bank screen (BankMovementLists) as SQL: of the
 * movements in DQL, over "m", and of the bank mail in plain SQL, over the message
 * "m" and its account "a". The direction and the state compile to what the tabs
 * counted with; each compile names its parameters from its prefix, so one count
 * query holds many views side by side (spec D9).
 *
 * @phpstan-type Parameters array<string, array{mixed, string|ArrayParameterType|null}>
 */
final readonly class MovementConditions
{
    public function __construct(private ClockInterface $clock)
    {
    }

    /**
     * @return array{string, Parameters} "1 = 1" for a view with no condition
     */
    public function movements(ViewFilter $view, string $prefix): array
    {
        $derived = fn (Condition $condition, string $name): array => match ($condition->field) {
            BankMovementLists::DIRECTION => [DqlConditions::derivedEnum($condition, $this->predicates('m.direction', MovementDirection::cases())), []],
            BankMovementLists::STATE => [DqlConditions::derivedEnum($condition, $this->predicates('m.state', MovementState::cases())), []],
            BankMovementLists::ACCOUNT => $this->account($condition, 'IDENTITY(m.account)', $name),
            // A movement is no bank mail.
            BankMovementLists::BANK_MAIL => [DqlConditions::derivedBoolean($condition, '1 = 0'), []],
            default => throw new \LogicException(\sprintf('The field "%s" of the bank movements has no SQL.', $condition->field)),
        };

        return new DqlConditions(BankMovementLists::of([]), [], $derived(...))->compile($view, $this->clock->now(...), $prefix);
    }

    /**
     * The messages a view of the bank mail holds: those of its accounts. A message has
     * no direction and no state, so "is" finds none and "is not" all of them.
     *
     * @return array{string, array<string, mixed>, array<string, ArrayParameterType>} the SQL, its parameters and their types
     */
    public function mail(ViewFilter $view, string $prefix): array
    {
        $parts = [];
        $parameters = [];
        $types = [];
        foreach ($view->conditions as $index => $condition) {
            $name = $prefix.'_'.$index;
            $negated = \in_array($condition->op, ['is_not', 'is_none_of'], true);
            if (BankMovementLists::ACCOUNT === $condition->field) {
                [$sql, $bound] = $this->account($condition, 'a.id', $name);
                $parts[] = '('.$sql.')';
                $parameters[$name] = $bound[$name][0];
                $types[$name] = ArrayParameterType::STRING;
            } elseif (BankMovementLists::BANK_MAIL === $condition->field) {
                $parts[] = true === $condition->value ? '1 = 1' : '1 = 0';
            } else {
                $parts[] = $negated ? '1 = 1' : '1 = 0';
            }
        }

        return [[] === $parts ? '1 = 1' : implode(' AND ', $parts), $parameters, $types];
    }

    /**
     * @template TKey
     * @template TResult
     *
     * @param Query<TKey, TResult> $query
     * @param Parameters           $parameters
     *
     * @return Query<TKey, TResult>
     */
    public static function bind(Query $query, array $parameters): Query
    {
        foreach ($parameters as $name => [$value, $type]) {
            $query->setParameter($name, $value, $type);
        }

        return $query;
    }

    /**
     * An account is always set, so its every operator is one list of ids, or its opposite.
     *
     * @return array{string, Parameters}
     */
    private function account(Condition $condition, string $column, string $name): array
    {
        $ids = \is_array($condition->value) ? array_values($condition->value) : [$condition->value];
        $in = $column.' IN (:'.$name.')';

        return [\in_array($condition->op, ['is_not', 'is_none_of'], true) ? 'NOT ('.$in.')' : $in, [$name => [$ids, ArrayParameterType::STRING]]];
    }

    /**
     * The predicate of each value: the module's own values, never what a person typed.
     *
     * @param list<\BackedEnum> $cases
     *
     * @return array<string, string>
     */
    private function predicates(string $column, array $cases): array
    {
        $predicates = [];
        foreach ($cases as $case) {
            $predicates[(string) $case->value] = $column.' = \''.$case->value.'\'';
        }

        return $predicates;
    }
}
