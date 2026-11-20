<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application;

use Foresi\Purchasing\Domain\ReceivedDocumentRefused;
use Foresi\Shared\Application\RefusalKind;
use Foresi\Shared\Application\Refused;

/**
 * What the received documents refuse over the API and MCP (ADR 0032): the
 * reason of ReceivedDocumentRefused with its kind and the input field it is
 * about, or a value the API could not read (a date, an amount, a currency or a
 * line of the VAT recap).
 */
final class PurchasingRefused extends \DomainException implements Refused
{
    /** A date is not YYYY-MM-DD. */
    public const string DATE = 'date_invalid';
    /** An amount is not a decimal number. */
    public const string AMOUNT = 'amount_invalid';
    /** A currency is not an ISO 4217 code Foresi knows. */
    public const string CURRENCY = 'currency_unsupported';
    /** A line of the VAT recap is not rate:base:vat or rate:base:vat:stated_vat_czk. */
    public const string VAT_RECAP_LINE = 'vat_recap_line_invalid';
    /** The firm is not one of the organisation. */
    public const string FIRM_UNKNOWN = 'firm_unknown';

    private const array CONFLICTS = [
        ReceivedDocumentRefused::TRASHED, ReceivedDocumentRefused::RECORDED, ReceivedDocumentRefused::STALE,
        ReceivedDocumentRefused::LOCKED, ReceivedDocumentRefused::PAID,
        ReceivedDocumentRefused::OVERPAID, ReceivedDocumentRefused::NOT_RECORDED,
        ReceivedDocumentRefused::EVIDENCE_NUMBERS_EXHAUSTED, ReceivedDocumentRefused::EVIDENCE_NUMBER_FIXED,
        ReceivedDocumentRefused::ORIGINAL_FILE_FIXED,
    ];

    private const array FIELDS = [
        ReceivedDocumentRefused::SUPPLIER_MISSING => 'supplierId',
        ReceivedDocumentRefused::SUPPLIER_UNKNOWN => 'supplierId',
        ReceivedDocumentRefused::ISSUE_DATE_MISSING => 'issueDate',
        ReceivedDocumentRefused::RECEIVED_DATE_MISSING => 'receivedDate',
        ReceivedDocumentRefused::TOTAL_MISSING => 'total',
        ReceivedDocumentRefused::VAT_RECAP_MISSING => 'vatRecap',
        ReceivedDocumentRefused::VAT_RECAP_CURRENCY => 'vatRecap',
        ReceivedDocumentRefused::VAT_RECAP_DUPLICATE_RATE => 'vatRecap',
        ReceivedDocumentRefused::VAT_RECAP_TOO_LONG => 'vatRecap',
        ReceivedDocumentRefused::VAT_RATE => 'vatRecap',
        ReceivedDocumentRefused::VAT_ON_NON_VAT_DOCUMENT => 'vatRecap',
        ReceivedDocumentRefused::STATED_VAT_CZK => 'vatRecap',
        ReceivedDocumentRefused::CURRENCY_MISMATCH => 'currency',
        ReceivedDocumentRefused::EXCHANGE_RATE => 'exchangeRate',
        ReceivedDocumentRefused::EXCHANGE_RATE_MISSING => 'exchangeRate',
        ReceivedDocumentRefused::CZK_RATE_NOT_ONE => 'exchangeRate',
        ReceivedDocumentRefused::ORIGINAL_MISSING => 'originalDocumentId',
        ReceivedDocumentRefused::ORIGINAL_NOT_CREDIT_NOTE => 'originalDocumentId',
        ReceivedDocumentRefused::ORIGINAL_INVALID => 'originalDocumentId',
        ReceivedDocumentRefused::SIMPLIFIED_SELF_ASSESSED => 'treatment',
        ReceivedDocumentRefused::TREATMENT_FOR_KIND => 'treatment',
        ReceivedDocumentRefused::SUPPLIER_NUMBER_TOO_LONG => 'supplierNumber',
        ReceivedDocumentRefused::VARIABLE_SYMBOL => 'variableSymbol',
        ReceivedDocumentRefused::REASON_MISSING => 'reason',
        ReceivedDocumentRefused::REASON_TOO_LONG => 'reason',
        ReceivedDocumentRefused::SUPPLY_CODE_NOT_ALLOWED => 'supplyCode',
        ReceivedDocumentRefused::SUPPLY_CODE_INVALID => 'supplyCode',
        ReceivedDocumentRefused::PAYMENT_AMOUNT => 'amount',
        ReceivedDocumentRefused::BANK_MOVEMENT_INVALID => 'bankMovementId',
    ];

    private const array MESSAGES = [
        ReceivedDocumentRefused::TRASHED => 'The received document is in the trash; restore it in Foresi first.',
        ReceivedDocumentRefused::NOT_RECORDED => 'The received document is a draft; only a recorded document takes payments. Record it in Foresi first.',
        ReceivedDocumentRefused::PAID => 'The received document is paid in full already.',
        ReceivedDocumentRefused::OVERPAID => 'The payment is more than what remains to be paid; read the document and pay at most its remaining amount.',
        ReceivedDocumentRefused::PAYMENT_AMOUNT => 'A payment needs an amount above zero, in the currency of the document.',
        ReceivedDocumentRefused::SUPPLIER_UNKNOWN => 'The organisation has no contact with this id. Find the supplier with foresi_search_contacts, or create it with foresi_create_contact.',
        ReceivedDocumentRefused::LOCKED => 'The document belongs to a closed month and cannot change.',
    ];

    private function __construct(
        private readonly string $reason,
        private readonly RefusalKind $kind,
        private readonly ?string $field,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function of(ReceivedDocumentRefused $refused): self
    {
        $reason = $refused->reason;
        $kind = match (true) {
            \in_array($reason, self::CONFLICTS, true) => RefusalKind::Conflict,
            ReceivedDocumentRefused::EXCHANGE_RATE_UNAVAILABLE === $reason => RefusalKind::Unavailable,
            default => RefusalKind::Invalid,
        };

        return new self($reason, $kind, self::FIELDS[$reason] ?? null, self::MESSAGES[$reason] ?? $refused->getMessage(), $refused);
    }

    /**
     * A value of the field the API could not read.
     */
    public static function unreadable(string $reason, string $field, string $message): self
    {
        return new self($reason, RefusalKind::Invalid, $field, $message);
    }

    public static function unknownFirm(?\Throwable $previous = null): self
    {
        return new self(self::FIRM_UNKNOWN, RefusalKind::NotFound, null, 'The instance has no firm yet: its first-run setup has not happened.', $previous);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function kind(): RefusalKind
    {
        return $this->kind;
    }

    public function field(): ?string
    {
        return $this->field;
    }

    public function details(): array
    {
        return [];
    }
}
