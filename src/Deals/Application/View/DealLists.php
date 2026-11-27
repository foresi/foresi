<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application\View;

use Foresi\Deals\Domain\DealStatus;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ListFields;
use Foresi\Shared\Application\View\QuickPick;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * The deals on saved views: All, Open, Won, Lost and Trash, with the status,
 * the stage, the owner and the client as conditions of the filter sheet. Closed deals leave the
 * open view and are found by their status, as Pipedrive and Odoo keep them.
 */
final class DealLists implements ListDefinitionSource
{
    public const string KEY = 'deals.deals';
    public const string STATUS = 'status';
    public const string OWNER = 'owner';
    public const string CLIENT = 'client';
    public const string STAGE = 'stage';
    public const string TRASHED = 'trashed';

    /** What an owner reference points to: an owner or member, picked from the people of the organisation. */
    public const string MEMBER = 'member';

    /** What a stage reference points to: one of the firm's active deal stages, picked from the list the page gives. */
    public const string STAGE_SOURCE = 'stage';

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
            new Field(self::STATUS, FieldType::Enum, 'deals.deals.field.status', options: $statuses, optionLabels: array_combine($statuses, array_map(static fn (string $status): string => 'deals.status.'.$status, $statuses))),
            new Field(self::STAGE, FieldType::Reference, 'deals.deals.field.stage', ['is', 'is_not', 'is_any_of'], source: self::STAGE_SOURCE, quick: QuickPick::Choices),
            new Field(self::OWNER, FieldType::Reference, 'deals.deals.field.owner', ['is', 'is_not', 'is_any_of'], source: self::MEMBER),
            new Field(self::CLIENT, FieldType::Reference, 'deals.deals.field.client', ['is', 'is_not', 'is_any_of'], source: self::CONTACT),
            new Field(self::TRASHED, FieldType::Boolean, 'deals.deals.field.trashed'),
        ]), $this->defaults(), [self::STAGE, self::OWNER]);
    }

    /**
     * @return list<DefaultView>
     */
    private function defaults(): array
    {
        $status = static fn (DealStatus $status): ViewFilter => ViewFilter::all(new Condition(self::STATUS, 'is', $status->value));

        return [
            new DefaultView(ListDefinition::ALL, 'deals.deals.tab.all', showCount: false, position: 0),
            new DefaultView(DealStatus::Open->value, 'deals.deals.tab.open', $status(DealStatus::Open), showCount: false, position: 1),
            new DefaultView(DealStatus::Won->value, 'deals.deals.tab.won', $status(DealStatus::Won), showCount: false, position: 2),
            new DefaultView(DealStatus::Lost->value, 'deals.deals.tab.lost', $status(DealStatus::Lost), showCount: false, position: 3),
            new DefaultView(self::TRASHED, 'deals.deals.tab.trash', ViewFilter::all(new Condition(self::TRASHED, 'is', true)), showCount: false, position: 4),
        ];
    }
}
