<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application\View;

use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;

/**
 * The two lists a tax document waits in: the payments of advances that still need
 * one, and the tax documents that need a corrective one. Their rows are worked out
 * by the VAT rules (MissingTaxDocuments, UncorrectedTaxDocuments), not read with one
 * query, so the controller filters them with RowConditions. Each ships its All; the
 * views people save sit beside it, as on every list (docs/design/list-pages.md).
 */
final readonly class TaxDocumentQueueLists implements ListDefinitionSource
{
    public const string MISSING = 'invoicing.advances_missing_tax_document';
    public const string UNCORRECTED = 'invoicing.tax_documents_uncorrected';

    public const string CUSTOMER = 'customer';
    public const string AMOUNT = 'amount';
    public const string RECEIVED_ON = 'received_on';
    public const string DECISIVE_DATE = 'decisive_date';
    public const string DEADLINE = 'deadline';
    public const string DEDUCTED = 'deducted';

    public function lists(): iterable
    {
        yield self::missing();
        yield self::uncorrected();
    }

    public static function missing(): ListDefinition
    {
        return new ListDefinition(self::MISSING, [
            new Field(self::CUSTOMER, FieldType::Text, 'advance.missing.column.customer', ['contains', 'does_not_contain', 'is']),
            new Field(self::AMOUNT, FieldType::Number, 'advance.missing.column.received'),
            new Field(self::RECEIVED_ON, FieldType::Date, 'advance.missing.column.receivedOn', ['is', 'before', 'after', 'on_or_before', 'on_or_after', 'between']),
            new Field(self::DEADLINE, FieldType::Date, 'advance.missing.column.deadline', ['is', 'before', 'after', 'on_or_before', 'on_or_after', 'between']),
        ], [new DefaultView(ListDefinition::ALL, 'invoice.list.tab.all')], [self::DEADLINE]);
    }

    public static function uncorrected(): ListDefinition
    {
        return new ListDefinition(self::UNCORRECTED, [
            new Field(self::CUSTOMER, FieldType::Text, 'tax_document.uncorrected.column.customer', ['contains', 'does_not_contain', 'is']),
            new Field(self::AMOUNT, FieldType::Number, 'tax_document.uncorrected.column.amount'),
            new Field(self::DECISIVE_DATE, FieldType::Date, 'tax_document.uncorrected.column.decisiveDate', ['is', 'before', 'after', 'on_or_before', 'on_or_after', 'between']),
            new Field(self::DEADLINE, FieldType::Date, 'tax_document.uncorrected.column.deadline', ['is', 'before', 'after', 'on_or_before', 'on_or_after', 'between']),
            new Field(self::DEDUCTED, FieldType::Boolean, 'tax_document.uncorrected.field.deducted'),
        ], [new DefaultView(ListDefinition::ALL, 'invoice.list.tab.all')], [self::DEADLINE, self::DEDUCTED]);
    }
}
