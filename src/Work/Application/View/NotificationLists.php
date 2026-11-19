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
use Foresi\Shared\Application\View\ViewFilter;

/**
 * The inbox and the views it ships: the two tabs it had (spec D12). Its All, named
 * All as on every list (D7), holds what is not done: a done row is hidden from a view
 * that says nothing of done, as an archived contact is from the address book's All,
 * so the inbox opens on what is new as before. Done keeps the rows marked done. No
 * tab counts (D9).
 *
 * Every field is derived from the row: why it came (a mention or an assignment, the
 * select the inbox had, D11), whether it is read or done, and when it last changed.
 */
final readonly class NotificationLists implements ListDefinitionSource
{
    public const string KEY = 'work.notifications';
    public const string DONE_VIEW = 'done';
    public const string REASON = 'reason';
    public const string MENTIONS = 'mentions';
    public const string ASSIGNED = 'assigned';
    public const string UNREAD = 'unread';
    public const string DONE = 'done';
    public const string AT = 'at';

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
        $reasons = [self::MENTIONS, self::ASSIGNED];

        return [
            new Field(self::REASON, FieldType::Enum, 'work.notifications.field.reason', options: $reasons, optionLabels: array_combine($reasons, array_map(static fn (string $reason): string => 'work.notifications.filter.'.$reason, $reasons))),
            new Field(self::UNREAD, FieldType::Boolean, 'work.notifications.field.unread'),
            new Field(self::DONE, FieldType::Boolean, 'work.notifications.tab.done'),
            new Field(self::AT, FieldType::Date, 'work.notifications.column.at', ['is', 'before', 'after', 'on_or_before', 'on_or_after', 'between']),
        ];
    }

    /**
     * @return list<DefaultView>
     */
    private static function defaults(): array
    {
        return [
            new DefaultView(ListDefinition::ALL, 'work.notifications.tab.all', showCount: false, position: 0),
            new DefaultView(self::DONE_VIEW, 'work.notifications.tab.done', ViewFilter::all(new Condition(self::DONE, 'is', true)), showCount: false, position: 1),
        ];
    }
}
