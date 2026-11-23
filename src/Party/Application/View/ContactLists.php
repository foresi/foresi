<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application\View;

use Foresi\Party\Domain\PartyRole;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ListFields;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * The address book and the views it ships: the tabs it had, each the same contacts
 * as before (spec D12). Its fields are the filterable attributes of its data source
 * (DS1), with the two the module compiles itself: whether a contact is archived and
 * whether the register lists it as an unreliable payer, a yes or no where the
 * attribute is the register's answer. "role" is an older key of the roles, which
 * views kept before the roles were a set.
 *
 * The list leaves out the archived contacts unless a condition asks for them: All
 * holds no condition, as every list's All, and still shows no archived contact.
 */
final class ContactLists implements ListDefinitionSource
{
    public const string KEY = 'party.contacts';
    public const string ARCHIVED = 'archived';
    public const string ROLES = 'roles';
    public const string UNRELIABLE_PAYER = 'unreliable_payer';

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
        return $this->list ??= new ListDefinition(self::KEY, $this->fields->of(self::KEY, [
            new Field(self::ARCHIVED, FieldType::Boolean, 'party.view.archived'),
            new Field(self::UNRELIABLE_PAYER, FieldType::Boolean, 'party.field.unreliablePayerResult'),
        ]), $this->defaults(), ['roles', 'kind']);
    }

    /**
     * @return list<DefaultView>
     */
    private function defaults(): array
    {
        return [
            new DefaultView('all', 'party.view.all', position: 0),
            new DefaultView('customers', 'party.view.customers', ViewFilter::all(new Condition(self::ROLES, 'has_any_of', [PartyRole::Customer->value])), position: 1),
            new DefaultView('suppliers', 'party.view.suppliers', ViewFilter::all(new Condition(self::ROLES, 'has_any_of', [PartyRole::Supplier->value])), position: 2),
            new DefaultView('archived', 'party.view.archived', ViewFilter::all(new Condition(self::ARCHIVED, 'is', true)), position: 3),
        ];
    }
}
