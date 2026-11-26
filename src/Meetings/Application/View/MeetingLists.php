<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application\View;

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
 * The meetings and the views they ship: All, Upcoming, Past and My meetings. The
 * quick filters under the tabs are the series (one choice per series the person
 * sees, as Fellow keeps a note series and Notion a meetings database filtered by
 * type), My meetings (the person attends) and when (upcoming or past). The title
 * is a filterable attribute of the list's data source, and the custom fields of a
 * meeting (ADR 0045) are its fields too.
 */
final class MeetingLists implements ListDefinitionSource
{
    public const string KEY = 'meetings.meetings';
    public const string SERIES = 'series';
    public const string MINE = 'mine';
    public const string WHEN = 'when';
    public const string HELD_ON = 'held_on';
    public const string UPCOMING = 'upcoming';
    public const string PAST = 'past';
    /** What the series filter points to, for the builder's picker. */
    public const string SOURCE_SERIES = 'meeting_series';

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
        return $this->list ??= new ListDefinition(self::KEY, $this->fields->of(self::KEY, $this->ownFields()), $this->defaults(), [self::SERIES, self::MINE, self::WHEN]);
    }

    /**
     * @return list<Field>
     */
    private function ownFields(): array
    {
        return [
            new Field(self::SERIES, FieldType::Reference, 'meetings.meetings.field.series', ['is', 'is_not', 'is_any_of', 'is_empty', 'is_not_empty'], source: self::SOURCE_SERIES, quick: QuickPick::Choices),
            new Field(self::MINE, FieldType::Boolean, 'meetings.meetings.field.mine'),
            new Field(self::WHEN, FieldType::Enum, 'meetings.meetings.field.when', ['is'], options: [self::UPCOMING, self::PAST], optionLabels: [self::UPCOMING => 'meetings.meetings.when.upcoming', self::PAST => 'meetings.meetings.when.past']),
            new Field(self::HELD_ON, FieldType::Date, 'meetings.meetings.field.held_on'),
        ];
    }

    /**
     * @return list<DefaultView>
     */
    private function defaults(): array
    {
        return [
            new DefaultView(ListDefinition::ALL, 'meetings.meetings.tab.all', showCount: false, position: 0),
            new DefaultView(self::UPCOMING, 'meetings.meetings.tab.upcoming', ViewFilter::all(new Condition(self::WHEN, 'is', self::UPCOMING)), showCount: false, position: 1),
            new DefaultView(self::PAST, 'meetings.meetings.tab.past', ViewFilter::all(new Condition(self::WHEN, 'is', self::PAST)), showCount: false, position: 2),
            new DefaultView(self::MINE, 'meetings.meetings.tab.mine', ViewFilter::all(new Condition(self::MINE, 'is', true)), showCount: false, position: 3),
        ];
    }
}
