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
use Foresi\Work\Domain\ProjectVisibility;

/**
 * The projects index and the views it ships: the tabs it had, each the same projects
 * as before (spec D12). The index counted none, so no view shows a count (D9).
 *
 * The list leaves out the archived projects unless a condition asks for them, as the
 * address book leaves out its archived contacts: All holds no condition, as every
 * list's All, and shows the projects the Active tab showed, so ?tab=active opens it.
 */
final readonly class ProjectLists implements ListDefinitionSource
{
    public const string KEY = 'work.projects';
    public const string ARCHIVED = 'archived';
    public const string CLIENT = 'client';
    public const string VISIBILITY = 'visibility';
    public const string BILLABLE = 'billable';

    /** What a client reference points to: the builder picks one with the contact picker. */
    public const string CONTACT = 'contact';

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
        $visibilities = array_column(ProjectVisibility::cases(), 'value');

        return [
            new Field(self::ARCHIVED, FieldType::Boolean, 'work.projects.field.archived'),
            new Field(self::CLIENT, FieldType::Reference, 'work.projects.column.client', source: self::CONTACT),
            new Field(self::VISIBILITY, FieldType::Enum, 'work.projects.field.visibility', options: $visibilities, optionLabels: array_combine($visibilities, array_map(static fn (string $value): string => 'work.sharing.visibility.'.$value, $visibilities))),
            new Field(self::BILLABLE, FieldType::Boolean, 'work.projects.field.billable'),
        ];
    }

    /**
     * @return list<DefaultView>
     */
    private static function defaults(): array
    {
        return [
            new DefaultView(ListDefinition::ALL, 'work.projects.tab.all', showCount: false, position: 0),
            new DefaultView(self::ARCHIVED, 'work.projects.tab.archived', ViewFilter::all(new Condition(self::ARCHIVED, 'is', true)), showCount: false, position: 1),
        ];
    }
}
