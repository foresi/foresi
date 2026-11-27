<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application\View;

use Foresi\Identity\Domain\Role;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ListFields;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * The people of the organisation on Settings → People as a list with views
 * (docs/design/list-pages.md, rule 8). It ships the two tabs the page always had: All, the
 * people who belong to it, and Invitations, the pending invitations, which only the owner
 * sees. An invitation shows only while the filter names it, as a trash view does.
 */
final class PeopleLists implements ListDefinitionSource
{
    public const string KEY = 'identity.people';
    public const string NAME = 'name';
    public const string EMAIL = 'email';
    public const string ROLE = 'role';
    public const string INVITED = 'invited';
    /** The slug of the invitations view, the address of the old invitations page. */
    public const string INVITATIONS = 'invitations';

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
        $roles = array_map(static fn (Role $role): string => $role->value, Role::cases());

        return $this->list ??= new ListDefinition(self::KEY, $this->fields->of(self::KEY, [
            new Field(self::NAME, FieldType::Text, 'people.column.name'),
            new Field(self::EMAIL, FieldType::Text, 'people.column.email'),
            new Field(self::ROLE, FieldType::Enum, 'people.column.role', options: $roles, optionLabels: array_combine($roles, array_map(static fn (string $role): string => 'role.'.$role, $roles))),
            new Field(self::INVITED, FieldType::Boolean, 'people.field.invited'),
        ]), [
            new DefaultView(ListDefinition::ALL, 'people.tab.people', showCount: false, position: 0),
            new DefaultView(self::INVITATIONS, 'people.tab.invitations', ViewFilter::all(new Condition(self::INVITED, 'is', true)), position: 1),
        ]);
    }
}
