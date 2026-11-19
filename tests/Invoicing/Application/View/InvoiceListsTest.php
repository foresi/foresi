<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application\View;

use Foresi\Invoicing\Application\InvoiceFilter;
use Foresi\Invoicing\Application\View\InvoiceLists;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\FieldType;
use PHPUnit\Framework\TestCase;

final class InvoiceListsTest extends TestCase
{
    public function testOnlyInvoicesAndAdvancesAreDueAndOverdue(): void
    {
        foreach (DocumentKind::cases() as $kind) {
            $fields = InvoiceLists::of($kind)->fields;
            $due = \in_array($kind, [DocumentKind::Invoice, DocumentKind::Advance], true);

            self::assertSame($due, isset($fields[InvoiceLists::DUE_DATE]), $kind->value);
            self::assertSame($due, isset($fields[InvoiceLists::OVERDUE]), $kind->value);
        }
    }

    public function testTheSettlementViewsFilterBySettlement(): void
    {
        $list = InvoiceLists::of(DocumentKind::PaymentTaxDocument);

        foreach ([InvoiceFilter::SETTLED, InvoiceFilter::UNSETTLED] as $tab) {
            self::assertEquals([new Condition(InvoiceLists::SETTLEMENT, 'is', $tab)], $list->default($tab)?->filter->conditions);
        }
    }

    public function testEveryListFiltersByTheExportForTheAccountant(): void
    {
        foreach (DocumentKind::cases() as $kind) {
            $field = InvoiceLists::of($kind)->fields[InvoiceLists::EXPORT];

            self::assertSame(FieldType::Enum, $field->type, $kind->value);
            self::assertSame('invoice.export_state.label', $field->label);
            self::assertSame(['is', 'is_any_of'], $field->operators);
            self::assertSame(['not_exported', 'exported', 'changed'], $field->options);
            self::assertSame(['not_exported' => 'invoice.export_state.not_exported', 'exported' => 'invoice.export_state.exported', 'changed' => 'invoice.export_state.changed'], $field->optionLabels);
        }
    }
}
