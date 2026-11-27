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
use Foresi\Work\Contract\ProjectClients;
use Foresi\Work\Domain\ProjectVisibility;

/**
 * The projects index and the views it ships: the tabs it had, each the same projects
 * as before (spec D12). The index counted none, so no view shows a count (D9).
 *
 * The list leaves out the archived projects unless a condition asks for them, as the
 * address book leaves out its archived contacts: All holds no condition, as every
 * list's All, and shows the projects the Active tab showed, so ?tab=active opens it.
 *
 * The name is a filterable attribute of the list's data source (DS1); the archive, the
 * client and the visibility are the module's own fields, and so are the task counts and
 * the next due date, read from the unfinished tasks the person sees. The client, pinned as
 * a quick filter, is there only where the address book is installed (ProjectClients).
 */
final class ProjectLists implements ListDefinitionSource
{
    public const string KEY = 'work.projects';
    public const string ARCHIVED = 'archived';
    public const string CLIENT = 'client';
    public const string VISIBILITY = 'visibility';
    public const string OPEN_TASKS = 'open_tasks';
    public const string OVERDUE_TASKS = 'overdue_tasks';
    public const string UNASSIGNED_TASKS = 'unassigned_tasks';
    public const string NEXT_DUE = 'next_due';

    /** A count is never empty: it compares alone. */
    private const array COUNT_OPERATORS = ['eq', 'ne', 'lt', 'le', 'gt', 'ge', 'between'];

    /** What a client reference points to: the builder picks one with the contact picker. */
    public const string CONTACT = 'contact';

    public function lists(): iterable
    {
        yield $this->list();
    }

    /** @var array<string, ListDefinition> by the audience of the fields (ListFields::audience()) */
    private array $list = [];

    public function __construct(private readonly ListFields $fields, private readonly ?ProjectClients $clients = null)
    {
    }

    public function list(): ListDefinition
    {
        return $this->list[$this->fields->audience()] ??= new ListDefinition(self::KEY, $this->fields->of(self::KEY, $this->ownFields()), $this->defaults(), $this->clients instanceof ProjectClients ? [self::CLIENT] : []);
    }

    /**
     * @return list<Field>
     */
    private function ownFields(): array
    {
        $visibilities = array_column(ProjectVisibility::cases(), 'value');

        return [
            new Field(self::ARCHIVED, FieldType::Boolean, 'work.projects.field.archived'),
            ...$this->clients instanceof ProjectClients ? [new Field(self::CLIENT, FieldType::Reference, 'work.projects.column.client', source: self::CONTACT)] : [],
            new Field(self::VISIBILITY, FieldType::Enum, 'work.projects.field.visibility', options: $visibilities, optionLabels: array_combine($visibilities, array_map(static fn (string $value): string => 'work.sharing.visibility.'.$value, $visibilities))),
            new Field(self::OPEN_TASKS, FieldType::Number, 'work.projects.field.open_tasks', self::COUNT_OPERATORS),
            new Field(self::OVERDUE_TASKS, FieldType::Number, 'work.projects.field.overdue_tasks', self::COUNT_OPERATORS),
            new Field(self::UNASSIGNED_TASKS, FieldType::Number, 'work.projects.field.unassigned_tasks', self::COUNT_OPERATORS),
            new Field(self::NEXT_DUE, FieldType::Date, 'work.projects.field.next_due'),
        ];
    }

    /**
     * @return list<DefaultView>
     */
    private function defaults(): array
    {
        return [
            new DefaultView(ListDefinition::ALL, 'work.projects.tab.all', showCount: false, position: 0),
            new DefaultView(self::ARCHIVED, 'work.projects.tab.archived', ViewFilter::all(new Condition(self::ARCHIVED, 'is', true)), showCount: false, position: 1),
        ];
    }
}
