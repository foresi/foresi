<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Application\View;

use Foresi\Purchasing\Application\View\ReceivedDocumentLists;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Domain\View\Placement;
use PHPUnit\Framework\TestCase;

/**
 * The received documents' list definition (saved views D3, D12): its fields and the tabs it had.
 */
final class ReceivedDocumentListsTest extends TestCase
{
    public function testItShipsAllAndTheTabsItHadInTheirOrderWithTheirCounts(): void
    {
        $list = ReceivedDocumentLists::list();

        self::assertEquals([$list], iterator_to_array(new ReceivedDocumentLists()->lists()));
        self::assertSame('purchasing.received', $list->key);
        self::assertSame(
            [
                ['all', 'purchasing.list.tab.all', [], 0],
                ['drafts', 'purchasing.list.tab.drafts', [['field' => 'state', 'op' => 'is', 'value' => 'draft']], 1],
                ['recorded', 'purchasing.list.tab.recorded', [['field' => 'state', 'op' => 'is', 'value' => 'recorded']], 2],
                ['trash', 'purchasing.list.tab.trash', [['field' => 'trashed', 'op' => 'is', 'value' => true]], 3],
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
        $fields = ReceivedDocumentLists::list()->fields;

        self::assertSame(['trashed', 'state', 'supplier', 'kind', 'treatment'], array_keys($fields));
        self::assertSame(['trashed' => 'purchasing.list.tab.trash', 'state' => 'purchasing.list.column.state', 'supplier' => 'purchasing.field.supplier', 'kind' => 'purchasing.field.kind', 'treatment' => 'purchasing.field.treatment'], array_map(static fn (Field $field): string => $field->label, $fields));
        self::assertSame(['draft' => 'purchasing.state.draft', 'recorded' => 'purchasing.state.recorded'], $fields['state']->optionLabels);
        self::assertSame('purchasing.kind.credit_note', $fields['kind']->optionLabels['credit_note']);
        self::assertSame('purchasing.treatment.eu_service', $fields['treatment']->optionLabels['eu_service']);
        self::assertSame(FieldType::Reference, $fields['supplier']->type);
        self::assertSame('contact', $fields['supplier']->source);
        self::assertSame(['is', 'is_not', 'is_any_of', 'is_empty', 'is_not_empty'], $fields['supplier']->operators);
        self::assertSame(FieldType::Boolean, $fields['trashed']->type);
    }
}
