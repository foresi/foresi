<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Domain;

use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Contract\Refused;

/**
 * What the margin refuses (ADR 0032), with a stable reason, the kind that
 * decides the status and the input field it is about. An allocation refuses
 * itself with whole_needs_one, percent_must_add_up, fixed_needs_a_rest or
 * duplicate_recipient; the use cases add unknown_contact, unknown_document,
 * unknown_line, the unreadable values and forbidden, and for the mappings of
 * planned items and suppliers unknown_planned_item, not_an_expense,
 * not_recurring, counterparty_required, unknown_supplier and invalid_month;
 * the margin table adds range_reversed and range_too_long, and a cell
 * invalid_row for a row that is no client, own or unassigned. Rebilling adds
 * invalid_day, unknown_template_line, unknown_invoice_line, not_an_occurrence,
 * already_paired, unknown_pairing and customer_not_a_recipient (also a
 * warning of a rebill link).
 */
final class MarginRefused extends \DomainException implements Refused
{
    public const string WHOLE_NEEDS_ONE = 'whole_needs_one';
    public const string PERCENT_MUST_ADD_UP = 'percent_must_add_up';
    public const string FIXED_NEEDS_A_REST = 'fixed_needs_a_rest';
    public const string DUPLICATE_RECIPIENT = 'duplicate_recipient';
    public const string UNKNOWN_CONTACT = 'unknown_contact';
    public const string UNKNOWN_DOCUMENT = 'unknown_document';
    public const string UNKNOWN_LINE = 'unknown_line';
    public const string KIND_INVALID = 'kind_invalid';
    public const string SHARE_INVALID = 'share_invalid';
    public const string RECIPIENT_INVALID = 'recipient_invalid';
    public const string AMOUNT_INVALID = 'amount_invalid';
    public const string FORBIDDEN = 'forbidden';
    public const string UNKNOWN_PLANNED_ITEM = 'unknown_planned_item';
    public const string NOT_AN_EXPENSE = 'not_an_expense';
    public const string NOT_RECURRING = 'not_recurring';
    public const string COUNTERPARTY_REQUIRED = 'counterparty_required';
    public const string UNKNOWN_SUPPLIER = 'unknown_supplier';
    public const string INVALID_MONTH = 'invalid_month';
    public const string RANGE_REVERSED = 'range_reversed';
    public const string RANGE_TOO_LONG = 'range_too_long';
    public const string INVALID_ROW = 'invalid_row';
    public const string INVALID_DAY = 'invalid_day';
    public const string UNKNOWN_TEMPLATE_LINE = 'unknown_template_line';
    public const string UNKNOWN_INVOICE_LINE = 'unknown_invoice_line';
    public const string NOT_AN_OCCURRENCE = 'not_an_occurrence';
    public const string ALREADY_PAIRED = 'already_paired';
    public const string UNKNOWN_PAIRING = 'unknown_pairing';
    public const string CUSTOMER_NOT_A_RECIPIENT = 'customer_not_a_recipient';

    public function __construct(
        private readonly string $reason,
        string $message,
        private readonly RefusalKind $kind = RefusalKind::Invalid,
        private readonly ?string $field = 'shares',
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $reason, string $field, string $message): self
    {
        return new self($reason, $message, RefusalKind::Invalid, $field);
    }

    public static function notFound(string $reason, string $message): self
    {
        return new self($reason, $message, RefusalKind::NotFound, null);
    }

    /**
     * The margin is the firm's: its owners and members work with it, and a
     * guest or anyone signed out is refused.
     */
    public static function forbidden(): self
    {
        return new self(self::FORBIDDEN, 'Only the owners and the members of the organisation work with its margin.', RefusalKind::Forbidden, null);
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
