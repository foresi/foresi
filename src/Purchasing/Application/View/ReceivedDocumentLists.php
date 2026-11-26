<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\View;

use Foresi\Purchasing\Domain\DocumentSource;
use Foresi\Purchasing\Domain\PurchaseTaxTreatment;
use Foresi\Purchasing\Domain\ReadingState;
use Foresi\Purchasing\Domain\ReceivedDocumentKind;
use Foresi\Purchasing\Domain\ReceivedDocumentState;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ListFields;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * The received documents and the views they ship: the tabs they had, each the same
 * documents as before (spec D12), after All, which every list starts with. The fields
 * the list's sheet had (supplier, kind, VAT treatment) are fields of the filter sheet (D11),
 * beside the filterable attributes of the list's data source (DS1): the numbers and the
 * amounts.
 *
 * The list leaves out the trash unless a condition asks for it: All holds no
 * condition, as every list's All, and still shows no document in the trash.
 * To review holds the drafts whose file was read, whatever it found, so a
 * person checks what was filled in before recording them.
 */
final class ReceivedDocumentLists implements ListDefinitionSource
{
    public const string KEY = 'purchasing.received';
    public const string TRASHED = 'trashed';
    public const string STATE = 'state';
    public const string SUPPLIER = 'supplier';
    public const string KIND = 'kind';
    public const string TREATMENT = 'treatment';
    /** The month a document belongs to: its tax point, its issue date from abroad (ReceivedDocumentFilter::monthDay). */
    public const string PERIOD = 'period';
    /** Where the reading of the document's file stands (ReadingState); a document without one has none. */
    public const string READING = 'reading';
    /** How the document came in (DocumentSource). */
    public const string SOURCE = 'source';

    public const string DRAFTS = 'drafts';
    public const string TO_REVIEW = 'to_review';
    public const string RECORDED = 'recorded';
    public const string TRASH = 'trash';

    /** What a supplier reference points to: the builder picks one with the contact picker. */
    public const string CONTACT = 'contact';

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
        return $this->list[$this->fields->audience()] ??= new ListDefinition(self::KEY, $this->fields->of(self::KEY, $this->ownFields()), self::defaults(), [self::PERIOD, self::SUPPLIER]);
    }

    /**
     * The filter of a default view, which holds only the module's own fields, so a query of a tab
     * needs no list built from the data source.
     */
    public static function defaultFilter(string $slug): ?ViewFilter
    {
        foreach (self::defaults() as $view) {
            if ($view->slug === $slug) {
                return $view->filter;
            }
        }

        return null;
    }

    /**
     * @return list<Field>
     */
    private function ownFields(): array
    {
        $states = array_column(ReceivedDocumentState::cases(), 'value');
        $kinds = array_column(ReceivedDocumentKind::cases(), 'value');
        $treatments = array_column(PurchaseTaxTreatment::cases(), 'value');
        $readings = array_column(ReadingState::cases(), 'value');
        $sources = array_column(DocumentSource::cases(), 'value');

        return [
            new Field(self::TRASHED, FieldType::Boolean, 'purchasing.list.tab.trash'),
            new Field(self::STATE, FieldType::Enum, 'purchasing.list.column.state', options: $states, optionLabels: $this->labels('purchasing.state.', $states)),
            new Field(self::SUPPLIER, FieldType::Reference, 'purchasing.field.supplier', ['is', 'is_not', 'is_any_of', 'is_empty', 'is_not_empty'], source: self::CONTACT),
            new Field(self::KIND, FieldType::Enum, 'purchasing.field.kind', options: $kinds, optionLabels: $this->labels('purchasing.kind.', $kinds)),
            new Field(self::TREATMENT, FieldType::Enum, 'purchasing.field.treatment', options: $treatments, optionLabels: $this->labels('purchasing.treatment.', $treatments)),
            new Field(self::PERIOD, FieldType::Date, 'purchasing.list.month', ['is']),
            new Field(self::READING, FieldType::Enum, 'purchasing.list.column.reading', options: $readings, optionLabels: $this->labels('purchasing.reading_state.', $readings)),
            new Field(self::SOURCE, FieldType::Enum, 'purchasing.list.column.source', options: $sources, optionLabels: $this->labels('purchasing.source.', $sources)),
        ];
    }

    /**
     * @param list<string> $values
     *
     * @return array<string, string>
     */
    private function labels(string $prefix, array $values): array
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
            new DefaultView(self::TO_REVIEW, 'purchasing.list.tab.to_review', ViewFilter::all(
                new Condition(self::STATE, 'is', ReceivedDocumentState::Draft->value),
                new Condition(self::READING, 'is_any_of', [ReadingState::Read->value, ReadingState::NothingFound->value, ReadingState::Failed->value]),
            ), position: 2),
            new DefaultView(self::RECORDED, 'purchasing.list.tab.recorded', ViewFilter::all(new Condition(self::STATE, 'is', ReceivedDocumentState::Recorded->value)), position: 3),
            new DefaultView(self::TRASH, 'purchasing.list.tab.trash', ViewFilter::all(new Condition(self::TRASHED, 'is', true)), position: 4),
        ];
    }
}
