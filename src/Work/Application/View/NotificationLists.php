<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application\View;

use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ListFields;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * The inbox and the views it ships: the two tabs it had (spec D12). Its All, named
 * All as on every list (D7), holds what is not done: a done row is hidden from a view
 * that says nothing of done, as an archived contact is from the address book's All,
 * so the inbox opens on what is new as before. Done keeps the rows marked done. No
 * tab counts (D9).
 *
 * The module derives why a row came (a mention or an assignment, the select the inbox
 * had, D11) and whether it is read or done; the task, the project and when it came are
 * the filterable attributes of the list's data source (DS1).
 */
final class NotificationLists implements ListDefinitionSource
{
    public const string KEY = 'work.notifications';
    public const string DONE_VIEW = 'done';
    public const string REASON = 'reason';
    public const string MENTIONS = 'mentions';
    public const string ASSIGNED = 'assigned';
    public const string UNREAD = 'unread';
    public const string DONE = 'done';

    public function lists(): iterable
    {
        yield $this->list();
    }

    /** @var array<string, ListDefinition> by the audience of the fields (ListFields::audience()) */
    private array $list = [];

    public function __construct(private readonly ListFields $fields)
    {
    }

    public function list(): ListDefinition
    {
        return $this->list[$this->fields->audience()] ??= new ListDefinition(self::KEY, $this->fields->of(self::KEY, $this->ownFields()), $this->defaults(), ['reason', 'unread']);
    }

    /**
     * @return list<Field>
     */
    private function ownFields(): array
    {
        $reasons = [self::MENTIONS, self::ASSIGNED];

        return [
            new Field(self::REASON, FieldType::Enum, 'work.notifications.field.reason', options: $reasons, optionLabels: array_combine($reasons, array_map(static fn (string $reason): string => 'work.notifications.filter.'.$reason, $reasons))),
            new Field(self::UNREAD, FieldType::Boolean, 'work.notifications.field.unread'),
            new Field(self::DONE, FieldType::Boolean, 'work.notifications.tab.done'),
        ];
    }

    /**
     * @return list<DefaultView>
     */
    private function defaults(): array
    {
        return [
            new DefaultView(ListDefinition::ALL, 'work.notifications.tab.all', showCount: false, position: 0),
            new DefaultView(self::DONE_VIEW, 'work.notifications.tab.done', ViewFilter::all(new Condition(self::DONE, 'is', true)), showCount: false, position: 1),
        ];
    }
}
