<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application\View;

use Foresi\Invoicing\Application\InvoiceFilter;
use Foresi\Invoicing\Domain\DocumentKind;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * The four lists of documents and the views they ship: the tabs they had, each
 * the same documents as before (spec D12). The payment, overdue and settlement
 * fields are derived, never stored, so DoctrineInvoiceStore compiles them with the
 * SQL the tabs counted with. Overdue keeps the day of the issuer's country
 * (ADR 0017), as the reminders and the rows do, rather than the instance's day.
 *
 * A list leaves out the trash unless a condition asks for it: All holds no
 * condition, as every list's All, and still shows no document in the trash.
 */
final readonly class InvoiceLists implements ListDefinitionSource
{
    public const string TRASHED = 'trashed';
    public const string PAYMENT = 'payment';
    public const string OVERDUE = 'overdue';
    public const string SETTLEMENT = 'settlement';
    public const string ISSUE_DATE = 'issue_date';
    public const string TAX_POINT_DATE = 'tax_point_date';
    public const string DUE_DATE = 'due_date';

    /** The key of each kind's list. */
    public const array KEYS = [
        'invoice' => 'invoicing.invoices',
        'advance' => 'invoicing.advances',
        'payment_tax_document' => 'invoicing.tax_documents',
        'correction_document' => 'invoicing.corrections',
    ];

    public function lists(): iterable
    {
        foreach (DocumentKind::cases() as $kind) {
            yield self::of($kind);
        }
    }

    public static function of(DocumentKind $kind): ListDefinition
    {
        return new ListDefinition(self::KEYS[$kind->value], self::fields($kind), self::defaults($kind));
    }

    /**
     * The date a month is counted by: a tax document's tax point, an advance's issue.
     */
    public static function monthField(DocumentKind $kind): string
    {
        return $kind->isTaxDocument() ? self::TAX_POINT_DATE : self::ISSUE_DATE;
    }

    /**
     * @return list<Field>
     */
    private static function fields(DocumentKind $kind): array
    {
        $fields = [new Field(self::TRASHED, FieldType::Boolean, 'invoice.list.tab.trash')];
        $payment = match ($kind) {
            DocumentKind::Invoice => [InvoiceFilter::PAID, InvoiceFilter::UNPAID, InvoiceFilter::TO_REFUND],
            DocumentKind::Advance => [InvoiceFilter::PAID, InvoiceFilter::UNPAID],
            DocumentKind::PaymentTaxDocument => [],
            DocumentKind::CorrectionDocument => [InvoiceFilter::TO_REFUND],
        };
        if ([] !== $payment) {
            $fields[] = new Field(self::PAYMENT, FieldType::Enum, 'invoice.field.payment_state', options: $payment);
        }
        if (\in_array($kind, [DocumentKind::Invoice, DocumentKind::Advance], true)) {
            $fields[] = new Field(self::OVERDUE, FieldType::Boolean, 'invoice.list.tab.overdue');
        }
        if (\in_array($kind, [DocumentKind::Advance, DocumentKind::PaymentTaxDocument], true)) {
            $fields[] = new Field(self::SETTLEMENT, FieldType::Enum, 'invoice.field.settlement', options: DocumentKind::Advance === $kind ? [InvoiceFilter::UNSETTLED] : [InvoiceFilter::SETTLED, InvoiceFilter::UNSETTLED]);
        }
        $fields[] = new Field(self::ISSUE_DATE, FieldType::Date, 'invoice.field.issueDate');
        if ($kind->isTaxDocument()) {
            $fields[] = new Field(self::TAX_POINT_DATE, FieldType::Date, 'invoice.field.taxPointDate');
        }
        if (\in_array($kind, [DocumentKind::Invoice, DocumentKind::Advance], true)) {
            $fields[] = new Field(self::DUE_DATE, FieldType::Date, 'invoice.field.dueDate');
        }

        return $fields;
    }

    /**
     * @return list<DefaultView>
     */
    private static function defaults(DocumentKind $kind): array
    {
        $views = [];
        foreach (InvoiceFilter::tabsOf($kind) as $position => $tab) {
            $views[] = new DefaultView($tab, 'invoice.list.tab.'.$tab, self::filterOf($tab), position: $position);
        }

        return $views;
    }

    private static function filterOf(string $tab): ViewFilter
    {
        return match ($tab) {
            InvoiceFilter::ALL => new ViewFilter(),
            InvoiceFilter::TRASH => ViewFilter::all(new Condition(self::TRASHED, 'is', true)),
            InvoiceFilter::PAID, InvoiceFilter::UNPAID, InvoiceFilter::TO_REFUND => ViewFilter::all(new Condition(self::PAYMENT, 'is', $tab)),
            InvoiceFilter::OVERDUE => ViewFilter::all(new Condition(self::OVERDUE, 'is', true)),
            // Settled and unsettled, the only tabs tabsOf() gives besides those above.
            default => ViewFilter::all(new Condition(self::SETTLEMENT, 'is', $tab)),
        };
    }
}
