<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\View;

use Foresi\Purchasing\Domain\PurchaseTaxTreatment;
use Foresi\Purchasing\Domain\ReceivedDocumentKind;
use Foresi\Purchasing\Domain\ReceivedDocumentState;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * The received documents and the views they ship: the tabs they had, each the same
 * documents as before (spec D12), after All, which every list starts with. The fields
 * the list's sheet had (supplier, kind, VAT treatment) are fields of "+ Filter" (D11).
 *
 * The list leaves out the trash unless a condition asks for it: All holds no
 * condition, as every list's All, and still shows no document in the trash.
 */
final readonly class ReceivedDocumentLists implements ListDefinitionSource
{
    public const string KEY = 'purchasing.received';
    public const string TRASHED = 'trashed';
    public const string STATE = 'state';
    public const string SUPPLIER = 'supplier';
    public const string KIND = 'kind';
    public const string TREATMENT = 'treatment';

    public const string DRAFTS = 'drafts';
    public const string RECORDED = 'recorded';
    public const string TRASH = 'trash';

    /** What a supplier reference points to: the builder picks one with the contact picker. */
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
        $states = array_column(ReceivedDocumentState::cases(), 'value');
        $kinds = array_column(ReceivedDocumentKind::cases(), 'value');
        $treatments = array_column(PurchaseTaxTreatment::cases(), 'value');

        return [
            new Field(self::TRASHED, FieldType::Boolean, 'purchasing.list.tab.trash'),
            new Field(self::STATE, FieldType::Enum, 'purchasing.list.column.state', options: $states, optionLabels: self::labels('purchasing.state.', $states)),
            new Field(self::SUPPLIER, FieldType::Reference, 'purchasing.field.supplier', ['is', 'is_not', 'is_any_of', 'is_empty', 'is_not_empty'], source: self::CONTACT),
            new Field(self::KIND, FieldType::Enum, 'purchasing.field.kind', options: $kinds, optionLabels: self::labels('purchasing.kind.', $kinds)),
            new Field(self::TREATMENT, FieldType::Enum, 'purchasing.field.treatment', options: $treatments, optionLabels: self::labels('purchasing.treatment.', $treatments)),
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
            new DefaultView(ListDefinition::ALL, 'purchasing.list.tab.all', position: 0),
            new DefaultView(self::DRAFTS, 'purchasing.list.tab.drafts', ViewFilter::all(new Condition(self::STATE, 'is', ReceivedDocumentState::Draft->value)), position: 1),
            new DefaultView(self::RECORDED, 'purchasing.list.tab.recorded', ViewFilter::all(new Condition(self::STATE, 'is', ReceivedDocumentState::Recorded->value)), position: 2),
            new DefaultView(self::TRASH, 'purchasing.list.tab.trash', ViewFilter::all(new Condition(self::TRASHED, 'is', true)), position: 3),
        ];
    }
}
