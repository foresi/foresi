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
use Foresi\Work\Application\DueBucket;
use Foresi\Work\Contract\ProjectClients;
use Foresi\Work\Domain\Priority;
use Foresi\Work\Domain\StatusType;

/**
 * Every task the person sees across the active projects, the home of the work of the firm
 * (plan 37.1): Teamwork's Everything, ClickUp's All Tasks and Productive's Tasks hold the
 * work of all projects in one list of saved filters, with My work beside it as a view.
 *
 * Its fields are those the small-firm tools filter by: the project and its client, who is
 * responsible (me, nobody, a person), how the task is mine, the type of its status (the
 * statuses are per project, their types the same everywhere), the priority, the labels, when
 * it is due (a bucket or a date), who created it and when, and when it last changed.
 *
 * A finished task is left out unless a condition asks about the status type, as in a
 * project's list. All holds no condition, as every list's All, so it is "All open"; Mine is
 * where a person lands, as Asana's My Tasks and Linear's My Issues are the personal home.
 * Overdue, Unassigned, High and urgent and No due date are Freelo's prebuilt filters and
 * Basecamp's Unassigned work. My priorities holds the tasks the person starred, in the
 * order they put them (plan 37.2, Freelo's "My priorities"); a star is the person's own.
 *
 * What a view groups by and how it sorts are kept with it; a shipped view that keeps
 * neither shows its own (groupOf, sortOf): Mine by when it is due, the rest ungrouped.
 *
 * The client is a field and a grouping only where the address book is installed
 * (ProjectClients); without it a project has no client.
 */
final class TaskLists implements ListDefinitionSource
{
    public const string KEY = 'work.tasks';

    public const string TITLE = 'title';
    public const string PROJECT = 'project';
    public const string CLIENT = 'client';
    public const string RESPONSIBLE = 'responsible';
    public const string INVOLVEMENT = 'involvement';
    public const string STATUS_TYPE = 'status_type';
    public const string PRIORITY = 'priority';
    public const string LABELS = 'labels';
    public const string DUE = 'due';
    public const string DUE_ON = 'due_on';
    public const string CREATED_BY = 'created_by';
    public const string CREATED = 'created';
    public const string UPDATED = 'updated';
    /** Whether the person looking starred the task for My priorities (plan 37.2). */
    public const string STARRED = 'starred';

    /** A person value that stands for the person looking, whoever that is. */
    public const string ME = 'me';

    /** What a reference points to, for the builder's pickers. */
    public const string SOURCE_PROJECT = 'project';
    public const string SOURCE_CONTACT = 'contact';
    public const string SOURCE_PERSON = 'person';
    public const string SOURCE_LABEL = 'label';

    /** The shipped views, by their slug. */
    public const string MINE = 'mine';
    public const string OVERDUE = 'overdue';
    public const string UNASSIGNED = 'unassigned';
    public const string HIGH = 'high';
    public const string NO_DUE = 'no_due';
    public const string PRIORITIES = 'priorities';

    /** What the list groups by, kept in a view's display as group_by. */
    public const string GROUP_NONE = 'none';
    public const string GROUP_CLIENT = 'client';
    public const string GROUP_PROJECT = 'project';
    public const string GROUP_RESPONSIBLE = 'responsible';
    public const string GROUP_PRIORITY = 'priority';
    public const string GROUP_STATUS_TYPE = 'status_type';
    public const string GROUP_DUE = 'due';
    public const array GROUPS = [self::GROUP_NONE, self::GROUP_CLIENT, self::GROUP_PROJECT, self::GROUP_RESPONSIBLE, self::GROUP_PRIORITY, self::GROUP_STATUS_TYPE, self::GROUP_DUE];

    /** What the list sorts by, as its address names it; a minus sorts backwards. */
    public const string SORT_DUE = 'due';
    public const string SORT_PRIORITY = 'priority';
    public const string SORT_CREATED = 'created';
    public const string SORT_UPDATED = 'updated';
    public const string SORT_PROJECT = 'project';
    public const string SORT_TITLE = 'title';
    /** The order the person gave their starred tasks; the tasks with no star of theirs last. */
    public const string SORT_MY_ORDER = 'my_order';
    public const array SORTS = [self::SORT_DUE, self::SORT_PRIORITY, self::SORT_CREATED, self::SORT_UPDATED, self::SORT_PROJECT, self::SORT_TITLE, self::SORT_MY_ORDER];
    /** The list's own order: by when the task is due, the undated last, then the highest priority. */
    public const string DEFAULT_SORT = self::SORT_DUE;

    /** @var array<string, ListDefinition> by the audience of the fields (ListFields::audience()) */
    private array $list = [];

    public function __construct(private readonly ListFields $fields, private readonly ?ProjectClients $clients = null)
    {
    }

    public function lists(): iterable
    {
        yield $this->list();
    }

    public function list(): ListDefinition
    {
        return $this->list[$this->fields->audience()] ??= new ListDefinition(self::KEY, $this->fields->of(self::KEY, self::ownFields($this->hasClients())), self::defaults(), [self::PROJECT, self::RESPONSIBLE, self::PRIORITY, self::DUE]);
    }

    /**
     * The list's definition without the fields other modules add: what code that knows no
     * ListFields reads, such as a test.
     *
     * @param bool $clients whether the address book is installed, so a project has a client
     */
    public static function plain(bool $clients = true): ListDefinition
    {
        return new ListDefinition(self::KEY, self::ownFields($clients), self::defaults(), [self::PROJECT, self::RESPONSIBLE, self::PRIORITY, self::DUE]);
    }

    /**
     * Whether a project has a client: where the address book is installed.
     */
    public function hasClients(): bool
    {
        return $this->clients instanceof ProjectClients;
    }

    /**
     * What a view groups by when it keeps nothing of its own: Mine by when its tasks are due,
     * as My tasks did, every other view not at all. The client is no grouping without the
     * address book.
     *
     * @return list<string> the groupings, the view's own first, as WorkingDisplay takes them
     */
    public static function groupsOf(?string $slug, bool $clients = true): array
    {
        $first = self::MINE === $slug ? self::GROUP_DUE : self::GROUP_NONE;
        $groups = $clients ? self::GROUPS : array_diff(self::GROUPS, [self::GROUP_CLIENT]);

        return [$first, ...array_values(array_diff($groups, [$first]))];
    }

    /**
     * How a view sorts when it keeps no sort: Mine by priority within its due groups, the
     * others by when the task is due.
     */
    public static function sortOf(?string $slug): string
    {
        return match ($slug) {
            self::MINE => '-'.self::SORT_PRIORITY,
            self::PRIORITIES => self::SORT_MY_ORDER,
            default => self::DEFAULT_SORT,
        };
    }

    /**
     * A sort as the address names it, or the fallback when it names no sort of the list.
     */
    public static function sort(string $sort, string $fallback): string
    {
        return \in_array(ltrim($sort, '-'), self::SORTS, true) ? $sort : $fallback;
    }

    /**
     * @return list<Field>
     */
    private static function ownFields(bool $clients): array
    {
        $person = static fn (string $key, string $label): Field => new Field($key, FieldType::Reference, $label, source: self::SOURCE_PERSON);
        $priorities = array_column(Priority::cases(), 'value');
        $types = array_column(StatusType::cases(), 'value');
        $dues = array_column(DueBucket::cases(), 'value');

        return [
            new Field(self::TITLE, FieldType::Text, 'work.task.field.title'),
            new Field(self::PROJECT, FieldType::Reference, 'work.task.field.project', source: self::SOURCE_PROJECT),
            ...$clients ? [new Field(self::CLIENT, FieldType::Reference, 'work.tasks.field.client', source: self::SOURCE_CONTACT)] : [],
            $person(self::RESPONSIBLE, 'work.filter.responsible'),
            new Field(self::INVOLVEMENT, FieldType::Enum, 'work.my_tasks.involvement', options: [MyTaskLists::RESPONSIBLE, MyTaskLists::WATCHER], optionLabels: [
                MyTaskLists::RESPONSIBLE => 'work.my_tasks.involvement_option.'.MyTaskLists::RESPONSIBLE,
                MyTaskLists::WATCHER => 'work.my_tasks.involvement_option.'.MyTaskLists::WATCHER,
            ]),
            new Field(self::STATUS_TYPE, FieldType::Enum, 'work.tasks.field.status_type', options: $types, optionLabels: self::labels('work.settings.type.', $types)),
            new Field(self::PRIORITY, FieldType::Enum, 'work.task.field.priority', options: $priorities, optionLabels: self::labels('work.priority.', $priorities)),
            new Field(self::LABELS, FieldType::Reference, 'work.task.field.labels', source: self::SOURCE_LABEL),
            new Field(self::DUE, FieldType::Enum, 'work.filter.due', options: $dues, optionLabels: self::labels('work.filter.due_', $dues)),
            new Field(self::DUE_ON, FieldType::Date, 'work.tasks.field.due_on'),
            $person(self::CREATED_BY, 'work.tasks.field.created_by'),
            new Field(self::CREATED, FieldType::Date, 'work.tasks.field.created'),
            new Field(self::UPDATED, FieldType::Date, 'work.tasks.field.updated'),
            new Field(self::STARRED, FieldType::Boolean, 'work.tasks.field.starred'),
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
     * No view counts its tasks in its tab: the list counts its groups instead.
     *
     * @return list<DefaultView>
     */
    private static function defaults(): array
    {
        return [
            new DefaultView(ListDefinition::ALL, 'work.tasks.tab.all', showCount: false, position: 0),
            new DefaultView(self::MINE, 'work.tasks.tab.mine', ViewFilter::all(new Condition(self::RESPONSIBLE, 'is', self::ME)), showCount: false, position: 1),
            new DefaultView(self::PRIORITIES, 'work.tasks.tab.priorities', ViewFilter::all(new Condition(self::STARRED, 'is', true)), showCount: false, position: 2),
            new DefaultView(self::OVERDUE, 'work.tasks.tab.overdue', ViewFilter::all(new Condition(self::DUE, 'is', DueBucket::Overdue->value)), showCount: false, position: 3),
            new DefaultView(self::UNASSIGNED, 'work.tasks.tab.unassigned', ViewFilter::all(new Condition(self::RESPONSIBLE, 'is_empty')), showCount: false, position: 4),
            new DefaultView(self::HIGH, 'work.tasks.tab.high', ViewFilter::all(new Condition(self::PRIORITY, 'is_any_of', [Priority::High->value, Priority::Urgent->value])), showCount: false, position: 5),
            new DefaultView(self::NO_DUE, 'work.tasks.tab.no_due', ViewFilter::all(new Condition(self::DUE, 'is', DueBucket::None->value)), showCount: false, position: 6),
        ];
    }
}
