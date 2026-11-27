<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ReceivedDocuments\Application\View;

use Foresi\ReceivedDocuments\Application\View\ReceivedDocumentLists;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Domain\View\Placement;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The received documents' list definition (saved views D3, D12): its fields and the tabs it had.
 */
final class ReceivedDocumentListsTest extends KernelTestCase
{
    public function testItShipsAllAndTheTabsItHadInTheirOrderWithTheirCounts(): void
    {
        $list = $this->lists()->list();

        self::assertEquals([$list], iterator_to_array($this->lists()->lists()));
        self::assertSame('received_documents.received', $list->key);
        self::assertSame(
            [
                ['all', 'received_documents.list.tab.all', [], 0],
                ['drafts', 'received_documents.list.tab.drafts', [['field' => 'state', 'op' => 'is', 'value' => 'draft']], 1],
                ['to_review', 'received_documents.list.tab.to_review', [['field' => 'state', 'op' => 'is', 'value' => 'draft'], ['field' => 'reading', 'op' => 'is_any_of', 'value' => ['read', 'nothing_found', 'failed']]], 2],
                ['recorded', 'received_documents.list.tab.recorded', [['field' => 'state', 'op' => 'is', 'value' => 'recorded']], 3],
                ['trash', 'received_documents.list.tab.trash', [['field' => 'trashed', 'op' => 'is', 'value' => true]], 4],
            ],
            array_map(static fn (DefaultView $view): array => [$view->slug, $view->name, $view->filter->toArray()['conditions'], $view->position], $list->defaults),
        );
        foreach ($list->defaults as $view) {
            self::assertTrue($view->showCount);
            self::assertSame(Placement::Primary, $view->placement);
        }
    }

    public function testItsFieldsAreWhatTheSheetFilteredBy(): void
    {
        // The condition of Cash flow's pairing is an integration's, so it is left out of what the module itself declares.
        $fields = array_diff_key($this->lists()->list()->fields, ['pairing_to_confirm' => true]);

        self::assertSame(['trashed', 'state', 'supplier', 'kind', 'treatment', 'period', 'reading', 'source', 'number', 'supplier_number', 'total', 'vat_czk'], array_keys($fields));
        self::assertSame(['trashed' => 'received_documents.list.tab.trash', 'state' => 'received_documents.list.column.state', 'supplier' => 'received_documents.field.supplier', 'kind' => 'received_documents.field.kind', 'treatment' => 'received_documents.field.treatment', 'period' => 'received_documents.list.month', 'reading' => 'received_documents.list.column.reading', 'source' => 'received_documents.list.column.source', 'number' => 'received_documents.list.column.number', 'supplier_number' => 'received_documents.list.column.supplierNumber', 'total' => 'received_documents.list.column.total', 'vat_czk' => 'received_documents.list.column.vatCzk'], array_map(static fn (Field $field): string => $field->label, $fields));
        self::assertSame(['draft' => 'received_documents.state.draft', 'recorded' => 'received_documents.state.recorded'], $fields['state']->optionLabels);
        self::assertSame('received_documents.kind.credit_note', $fields['kind']->optionLabels['credit_note']);
        self::assertSame('received_documents.treatment.eu_service', $fields['treatment']->optionLabels['eu_service']);
        self::assertSame(['reading', 'read', 'nothing_found', 'failed'], $fields['reading']->options);
        self::assertSame(['manual', 'upload', 'api', 'mail'], $fields['source']->options);
        self::assertSame(FieldType::Reference, $fields['supplier']->type);
        self::assertSame('contact', $fields['supplier']->source);
        self::assertSame(['is', 'is_not', 'is_any_of', 'is_empty', 'is_not_empty'], $fields['supplier']->operators);
        self::assertSame(FieldType::Boolean, $fields['trashed']->type);
        self::assertSame(FieldType::Number, $fields['total']->type);
        self::assertSame(100, $fields['total']->scale);
    }

    public function testTheListIsBuiltOnce(): void
    {
        self::assertSame($this->lists()->list(), $this->lists()->list());
    }

    private function lists(): ReceivedDocumentLists
    {
        return self::getContainer()->get(ReceivedDocumentLists::class);
    }
}
