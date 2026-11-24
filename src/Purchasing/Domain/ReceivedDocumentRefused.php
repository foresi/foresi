<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Domain;

/**
 * Why a received document refuses a change. The reason is stable: the API
 * returns it and it is the last part of a translation key under
 * purchasing.refused.
 */
final class ReceivedDocumentRefused extends \DomainException
{
    public const string TRASHED = 'trashed';
    public const string RECORDED = 'recorded';
    public const string SUPPLIER_MISSING = 'supplier_missing';
    public const string ISSUE_DATE_MISSING = 'issue_date_missing';
    public const string RECEIVED_DATE_MISSING = 'received_date_missing';
    public const string TOTAL_MISSING = 'total_missing';
    public const string VAT_RECAP_MISSING = 'vat_recap_missing';
    public const string VAT_RECAP_CURRENCY = 'vat_recap_currency';
    public const string VAT_RECAP_DUPLICATE_RATE = 'vat_recap_duplicate_rate';
    public const string VAT_RECAP_TOO_LONG = 'vat_recap_too_long';
    public const string VAT_RATE = 'vat_rate';
    public const string VAT_ON_NON_VAT_DOCUMENT = 'vat_on_non_vat_document';
    public const string STATED_VAT_CZK = 'stated_vat_czk';
    public const string CURRENCY_MISMATCH = 'currency_mismatch';
    public const string EXCHANGE_RATE = 'exchange_rate';
    public const string EXCHANGE_RATE_MISSING = 'exchange_rate_missing';
    public const string CZK_RATE_NOT_ONE = 'czk_rate_not_one';
    public const string ORIGINAL_MISSING = 'original_missing';
    public const string ORIGINAL_NOT_CREDIT_NOTE = 'original_not_credit_note';
    public const string ORIGINAL_INVALID = 'original_invalid';
    public const string SIMPLIFIED_SELF_ASSESSED = 'simplified_self_assessed';
    public const string TREATMENT_FOR_KIND = 'treatment_for_kind';
    public const string SUPPLIER_NUMBER_TOO_LONG = 'supplier_number_too_long';
    public const string VARIABLE_SYMBOL = 'variable_symbol';
    public const string EVIDENCE_NUMBER_MISSING = 'evidence_number_missing';
    public const string EVIDENCE_NUMBER_FIXED = 'evidence_number_fixed';
    public const string EVIDENCE_NUMBER_INVALID = 'evidence_number_invalid';
    public const string ORIGINAL_FILE_FIXED = 'original_file_fixed';
    public const string ORIGINAL_FILE_INVALID = 'original_file_invalid';
    public const string REASON_MISSING = 'reason_missing';
    public const string REASON_TOO_LONG = 'reason_too_long';
    public const string SUPPLY_CODE_NOT_ALLOWED = 'supply_code_not_allowed';
    public const string SUPPLY_CODE_INVALID = 'supply_code_invalid';
    // Refused by the lines of a document (DocumentLines).
    public const string LINES_DO_NOT_ADD_UP = 'lines_do_not_add_up';
    public const string UNKNOWN_VAT_RATE = 'unknown_vat_rate';
    public const string LINE_TEXT_REQUIRED = 'line_text_required';
    public const string LINE_TEXT_TOO_LONG = 'line_text_too_long';
    public const string LINES_TOO_MANY = 'lines_too_many';
    public const string DUPLICATE_LINE_ID = 'duplicate_line_id';
    // Refused by the use cases, which know more than the document.
    public const string SUPPLIER_UNKNOWN = 'supplier_unknown';
    public const string EXCHANGE_RATE_UNAVAILABLE = 'exchange_rate_unavailable';
    public const string EVIDENCE_NUMBERS_EXHAUSTED = 'evidence_numbers_exhausted';
    public const string STALE = 'stale';
    public const string LOCKED = 'locked';
    // Refused by the payments of a document.
    public const string NOT_RECORDED = 'not_recorded';
    public const string PAYMENT_AMOUNT = 'payment_amount';
    public const string OVERPAID = 'overpaid';
    public const string PAID = 'paid';
    public const string BANK_MOVEMENT_INVALID = 'bank_movement_invalid';
    // Refused by reading the file of a draft again.
    public const string READING_IN_PROGRESS = 'reading_in_progress';
    public const string NO_ORIGINAL_FILE = 'no_original_file';

    public const array REASONS = [
        self::TRASHED, self::RECORDED, self::SUPPLIER_MISSING, self::ISSUE_DATE_MISSING, self::RECEIVED_DATE_MISSING,
        self::TOTAL_MISSING, self::VAT_RECAP_MISSING, self::VAT_RECAP_CURRENCY, self::VAT_RECAP_DUPLICATE_RATE,
        self::VAT_RECAP_TOO_LONG, self::VAT_RATE, self::VAT_ON_NON_VAT_DOCUMENT, self::STATED_VAT_CZK,
        self::CURRENCY_MISMATCH, self::EXCHANGE_RATE, self::EXCHANGE_RATE_MISSING, self::CZK_RATE_NOT_ONE,
        self::ORIGINAL_MISSING, self::ORIGINAL_NOT_CREDIT_NOTE, self::ORIGINAL_INVALID, self::SIMPLIFIED_SELF_ASSESSED,
        self::TREATMENT_FOR_KIND, self::SUPPLIER_NUMBER_TOO_LONG, self::VARIABLE_SYMBOL, self::EVIDENCE_NUMBER_MISSING,
        self::EVIDENCE_NUMBER_FIXED, self::EVIDENCE_NUMBER_INVALID, self::ORIGINAL_FILE_FIXED, self::ORIGINAL_FILE_INVALID,
        self::REASON_MISSING, self::REASON_TOO_LONG, self::SUPPLY_CODE_NOT_ALLOWED, self::SUPPLY_CODE_INVALID,
        self::LINES_DO_NOT_ADD_UP, self::UNKNOWN_VAT_RATE, self::LINE_TEXT_REQUIRED, self::LINE_TEXT_TOO_LONG, self::LINES_TOO_MANY, self::DUPLICATE_LINE_ID,
        self::SUPPLIER_UNKNOWN, self::EXCHANGE_RATE_UNAVAILABLE, self::EVIDENCE_NUMBERS_EXHAUSTED,
        self::STALE, self::LOCKED, self::NOT_RECORDED, self::PAYMENT_AMOUNT, self::OVERPAID, self::PAID,
        self::BANK_MOVEMENT_INVALID, self::READING_IN_PROGRESS, self::NO_ORIGINAL_FILE,
    ];

    public function __construct(public readonly string $reason)
    {
        if (!\in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown reason "%s".', $reason));
        }
        parent::__construct(\sprintf('The received document refuses the change: %s.', $reason));
    }
}
