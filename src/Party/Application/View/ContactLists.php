<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application\View;

use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * The address book and the views it ships: the tabs it had, each the same contacts
 * as before (spec D12). A role lives in a JSON column, so PartyConditions compiles
 * the fields with the SQL the tabs counted with.
 *
 * The list leaves out the archived contacts unless a condition asks for them: All
 * holds no condition, as every list's All, and still shows no archived contact.
 */
final readonly class ContactLists implements ListDefinitionSource
{
    public const string KEY = 'party.contacts';
    public const string ARCHIVED = 'archived';
    public const string ROLE = 'role';
    public const string KIND = 'kind';
    public const string UNRELIABLE_PAYER = 'unreliable_payer';

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
        $roles = array_column(PartyRole::cases(), 'value');
        $kinds = array_column(PartyKind::cases(), 'value');

        return [
            new Field(self::ARCHIVED, FieldType::Boolean, 'party.view.archived'),
            new Field(self::ROLE, FieldType::Enum, 'party.column.roles', options: $roles, optionLabels: self::labels('party.role.', $roles)),
            new Field(self::KIND, FieldType::Enum, 'party.column.kind', options: $kinds, optionLabels: self::labels('party.kind.', $kinds)),
            new Field(self::UNRELIABLE_PAYER, FieldType::Boolean, 'party.field.unreliablePayerResult'),
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
     * @return list<DefaultView>
     */
    private static function defaults(): array
    {
        return [
            new DefaultView('all', 'party.view.all', position: 0),
            new DefaultView('customers', 'party.view.customers', ViewFilter::all(new Condition(self::ROLE, 'is', PartyRole::Customer->value)), position: 1),
            new DefaultView('suppliers', 'party.view.suppliers', ViewFilter::all(new Condition(self::ROLE, 'is', PartyRole::Supplier->value)), position: 2),
            new DefaultView('archived', 'party.view.archived', ViewFilter::all(new Condition(self::ARCHIVED, 'is', true)), position: 3),
        ];
    }
}
