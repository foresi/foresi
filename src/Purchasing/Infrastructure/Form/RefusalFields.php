<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Form;

use App\Purchasing\Domain\ReceivedDocumentRefused;

/**
 * Where the form of a received document tells a refusal: next to the field
 * that fixes it, or over the form when no field does (the trash, a lock, a
 * form opened before the last save).
 */
final class RefusalFields
{
    /** The refusals no field of the form fixes. */
    public const string FORM = 'form';

    private const array FIELDS = [
        ReceivedDocumentRefused::SUPPLIER_MISSING => 'supplier',
        ReceivedDocumentRefused::SUPPLIER_UNKNOWN => 'supplier',
        ReceivedDocumentRefused::ISSUE_DATE_MISSING => 'issueDate',
        ReceivedDocumentRefused::RECEIVED_DATE_MISSING => 'receivedDate',
        ReceivedDocumentRefused::TOTAL_MISSING => 'total',
        ReceivedDocumentRefused::CURRENCY_MISMATCH => 'total',
        ReceivedDocumentRefused::VAT_RECAP_MISSING => 'recap-0-rate',
        ReceivedDocumentRefused::VAT_RECAP_CURRENCY => 'recap-0-rate',
        ReceivedDocumentRefused::VAT_RECAP_DUPLICATE_RATE => 'recap-0-rate',
        ReceivedDocumentRefused::VAT_RECAP_TOO_LONG => 'recap-0-rate',
        ReceivedDocumentRefused::VAT_RATE => 'recap-0-rate',
        ReceivedDocumentRefused::VAT_ON_NON_VAT_DOCUMENT => 'recap-0-vat',
        ReceivedDocumentRefused::STATED_VAT_CZK => 'recap-0-statedVatCzk',
        ReceivedDocumentRefused::EXCHANGE_RATE => 'exchangeRate',
        ReceivedDocumentRefused::EXCHANGE_RATE_MISSING => 'exchangeRate',
        ReceivedDocumentRefused::EXCHANGE_RATE_UNAVAILABLE => 'exchangeRate',
        ReceivedDocumentRefused::CZK_RATE_NOT_ONE => 'exchangeRate',
        ReceivedDocumentRefused::ORIGINAL_MISSING => 'originalDocumentId',
        ReceivedDocumentRefused::ORIGINAL_NOT_CREDIT_NOTE => 'originalDocumentId',
        ReceivedDocumentRefused::ORIGINAL_INVALID => 'originalDocumentId',
        ReceivedDocumentRefused::SIMPLIFIED_SELF_ASSESSED => 'treatment',
        ReceivedDocumentRefused::TREATMENT_FOR_KIND => 'treatment',
        ReceivedDocumentRefused::SUPPLIER_NUMBER_TOO_LONG => 'supplierNumber',
        ReceivedDocumentRefused::VARIABLE_SYMBOL => 'variableSymbol',
        ReceivedDocumentRefused::SUPPLY_CODE_NOT_ALLOWED => 'supplyCode',
        ReceivedDocumentRefused::SUPPLY_CODE_INVALID => 'supplyCode',
        ReceivedDocumentRefused::FIRM_ARCHIVED => 'firm',
        ReceivedDocumentRefused::REASON_MISSING => 'reason',
        ReceivedDocumentRefused::REASON_TOO_LONG => 'reason',
    ];

    public static function of(string $reason): string
    {
        return self::FIELDS[$reason] ?? self::FORM;
    }
}
