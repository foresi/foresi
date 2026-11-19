<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application\View;

use Foresi\Invoicing\Application\InvoiceFilter;
use Foresi\Invoicing\Application\View\InvoiceLists;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Party\Domain\Party;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ViewFilter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The lists of documents (saved views D3, D12): their fields, derived from their data source
 * (DS1) beside the module's own, and the tabs they had.
 */
final class InvoiceListsTest extends KernelTestCase
{
    public function testOnlyInvoicesAndAdvancesAreOverdue(): void
    {
        foreach (DocumentKind::cases() as $kind) {
            $fields = $this->lists()->of($kind)->fields;

            self::assertSame(\in_array($kind, [DocumentKind::Invoice, DocumentKind::Advance], true), isset($fields[InvoiceLists::OVERDUE]), $kind->value);
        }
    }

    public function testEveryAttributeWithAnExpressionIsAFieldBesideTheModulesOwn(): void
    {
        $fields = $this->lists()->of(DocumentKind::Invoice)->fields;

        self::assertSame([
            'trashed', 'payment', 'overdue', 'export', 'customer', 'number', 'issue_date', 'deleted_at', 'due_date', 'deleted_reason', 'payable', 'sent',
            'customer_registration_number', 'customer_vat_id', 'customer_city', 'customer_country', 'customer_email', 'tax_point_date', 'currency',
            'net', 'vat', 'payment_method', 'bank_account', 'variable_symbol', 'order_number', 'language', 'vat_regime', 'description',
            'created_at', 'import_source',
            // The live customer contact (DS6): every field of the contact list's data source, one hop.
            'customer.name', 'customer.kind', 'customer.roles', 'customer.registration_number', 'customer.vat_id', 'customer.email',
            'customer.phone', 'customer.mobile', 'customer.website', 'customer.registration_country', 'customer.billing_city',
            'customer.billing_country', 'customer.default_due_days', 'customer.currency', 'customer.payment_method', 'customer.language',
            'customer.reverse_charge', 'customer.reminders', 'customer.hourly_rate', 'customer.vies_result', 'customer.archived_at',
        ], array_keys($fields));
        self::assertSame('invoice.relation.customer', $fields['customer.phone']->group);
        self::assertSame('p.phone', $fields['customer.phone']->expression);
        self::assertSame(Party::class.' p WHERE p = i.customerParty', $fields['customer.phone']->within);
        self::assertSame(100, $fields['customer.hourly_rate']->scale);
        self::assertSame(FieldType::Reference, $fields['customer']->type);
        self::assertSame(FieldType::Date, $fields['due_date']->type);
        self::assertSame('i.dueDate', $fields['due_date']->expression);
        self::assertSame(FieldType::Number, $fields['payable']->type);
        self::assertSame(100, $fields['payable']->scale);
        self::assertSame('i.number', $fields['number']->expression);
    }

    public function testAViewKeptOnTheAmountReadsAsTheTotalToPay(): void
    {
        $list = $this->lists()->of(DocumentKind::Invoice);

        self::assertEquals(ViewFilter::all(new Condition('payable', 'gt', 1000)), ViewFilter::parse(['conditions' => [['field' => InvoiceLists::AMOUNT, 'op' => 'gt', 'value' => 1000]]], $list));
    }

    public function testTheSettlementViewsFilterBySettlement(): void
    {
        $list = $this->lists()->of(DocumentKind::PaymentTaxDocument);

        foreach ([InvoiceFilter::SETTLED, InvoiceFilter::UNSETTLED] as $tab) {
            self::assertEquals([new Condition(InvoiceLists::SETTLEMENT, 'is', $tab)], $list->default($tab)?->filter->conditions);
        }
    }

    public function testEveryListFiltersByTheExportForTheAccountant(): void
    {
        foreach (DocumentKind::cases() as $kind) {
            $field = $this->lists()->of($kind)->fields[InvoiceLists::EXPORT];

            self::assertSame(FieldType::Enum, $field->type, $kind->value);
            self::assertSame('invoice.export_state.label', $field->label);
            self::assertSame(['is', 'is_any_of'], $field->operators);
            self::assertSame(['not_exported', 'exported', 'changed'], $field->options);
            self::assertSame(['not_exported' => 'invoice.export_state.not_exported', 'exported' => 'invoice.export_state.exported', 'changed' => 'invoice.export_state.changed'], $field->optionLabels);
        }
    }

    public function testTheListIsBuiltOnce(): void
    {
        self::assertSame($this->lists()->of(DocumentKind::Invoice), $this->lists()->of(DocumentKind::Invoice));
    }

    private function lists(): InvoiceLists
    {
        return self::getContainer()->get(InvoiceLists::class);
    }
}
