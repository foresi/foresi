<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application\View;

use Foresi\Invoicing\Domain\RecurringDelivery;
use Foresi\Invoicing\Domain\RecurringState;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * The recurring invoices and the views they ship: the tabs the list had, each the
 * same templates as before (spec D12), after All, which every list has (D7). Failing
 * is derived, never stored, so DoctrineRecurringInvoices compiles it with the SQL the
 * tab counted with.
 */
final readonly class RecurringLists implements ListDefinitionSource
{
    public const string KEY = 'invoicing.recurring';
    public const string STATE = 'state';
    public const string FAILING = 'failing';
    public const string DELIVERY = 'delivery';
    public const string NEXT_OCCURRENCE = 'next_occurrence';
    public const string NAME = 'name';

    public function lists(): iterable
    {
        yield self::list();
    }

    public static function list(): ListDefinition
    {
        return new ListDefinition(self::KEY, self::fields(), self::defaults());
    }

    /**
     * @return list<Field>
     */
    private static function fields(): array
    {
        $states = array_column(RecurringState::cases(), 'value');
        $deliveries = array_column(RecurringDelivery::cases(), 'value');

        return [
            new Field(self::STATE, FieldType::Enum, 'recurring.list.column.state', options: $states, optionLabels: self::labels('recurring.list.tab.', $states)),
            new Field(self::FAILING, FieldType::Boolean, 'recurring.list.tab.failing'),
            new Field(self::DELIVERY, FieldType::Enum, 'recurring.list.column.delivery', options: $deliveries, optionLabels: self::labels('recurring.list.delivery.', $deliveries)),
            new Field(self::NEXT_OCCURRENCE, FieldType::Date, 'recurring.list.column.next'),
            new Field(self::NAME, FieldType::Text, 'recurring.list.column.name'),
        ];
    }

    /**
     * @param list<string> $values
     *
     * @return array<string, string>
     */
    private static function labels(string $prefix, array $values): array
    {
        return array_combine($values, array_map(static fn (string $value): string => $prefix.$value, $values));
    }

    /**
     * @return list<DefaultView>
     */
    private static function defaults(): array
    {
        $state = static fn (RecurringState $state): ViewFilter => ViewFilter::all(new Condition(self::STATE, 'is', $state->value));

        return [
            new DefaultView(ListDefinition::ALL, 'recurring.list.tab.all', position: 0),
            new DefaultView('active', 'recurring.list.tab.active', $state(RecurringState::Active), position: 1),
            new DefaultView('paused', 'recurring.list.tab.paused', $state(RecurringState::Paused), position: 2),
            new DefaultView('ended', 'recurring.list.tab.ended', $state(RecurringState::Ended), position: 3),
            new DefaultView('failing', 'recurring.list.tab.failing', ViewFilter::all(new Condition(self::FAILING, 'is', true)), position: 4),
        ];
    }
}
