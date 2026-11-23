<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application\View;

use Foresi\Sales\Domain\DealStatus;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ListFields;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * The deals on saved views: All, Open, Won, Lost and Trash, with the status,
 * the owner and the client as conditions of "+ Filter". Closed deals leave the
 * open view and are found by their status, as Pipedrive and Odoo keep them.
 */
final class DealLists implements ListDefinitionSource
{
    public const string KEY = 'sales.deals';
    public const string STATUS = 'status';
    public const string OWNER = 'owner';
    public const string CLIENT = 'client';
    public const string TRASHED = 'trashed';

    /** What an owner reference points to: an owner or member, picked from the people of the organisation. */
    public const string MEMBER = 'member';

    /** What a client reference points to: the builder picks one with the contact picker. */
    public const string CONTACT = 'contact';

    private ?ListDefinition $list = null;

    public function __construct(private readonly ListFields $fields)
    {
    }

    public function lists(): iterable
    {
        yield $this->list();
    }

    public function list(): ListDefinition
    {
        $statuses = array_column(DealStatus::cases(), 'value');

        return $this->list ??= new ListDefinition(self::KEY, $this->fields->of(self::KEY, [
            new Field(self::STATUS, FieldType::Enum, 'sales.deals.field.status', options: $statuses, optionLabels: array_combine($statuses, array_map(static fn (string $status): string => 'sales.status.'.$status, $statuses))),
            new Field(self::OWNER, FieldType::Reference, 'sales.deals.field.owner', ['is', 'is_not', 'is_any_of'], source: self::MEMBER),
            new Field(self::CLIENT, FieldType::Reference, 'sales.deals.field.client', ['is', 'is_not', 'is_any_of'], source: self::CONTACT),
            new Field(self::TRASHED, FieldType::Boolean, 'sales.deals.field.trashed'),
        ]), $this->defaults(), [self::STATUS, self::OWNER]);
    }

    /**
     * @return list<DefaultView>
     */
    private function defaults(): array
    {
        $status = static fn (DealStatus $status): ViewFilter => ViewFilter::all(new Condition(self::STATUS, 'is', $status->value));

        return [
            new DefaultView(ListDefinition::ALL, 'sales.deals.tab.all', showCount: false, position: 0),
            new DefaultView(DealStatus::Open->value, 'sales.deals.tab.open', $status(DealStatus::Open), showCount: false, position: 1),
            new DefaultView(DealStatus::Won->value, 'sales.deals.tab.won', $status(DealStatus::Won), showCount: false, position: 2),
            new DefaultView(DealStatus::Lost->value, 'sales.deals.tab.lost', $status(DealStatus::Lost), showCount: false, position: 3),
            new DefaultView(self::TRASHED, 'sales.deals.tab.trash', ViewFilter::all(new Condition(self::TRASHED, 'is', true)), showCount: false, position: 4),
        ];
    }
}
