<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Domain;

use Foresi\Shared\Application\RefusalKind;
use Foresi\Shared\Application\Refused;

/**
 * What the margin refuses (ADR 0032), with a stable reason, the kind that
 * decides the status and the input field it is about. An allocation refuses
 * itself with whole_needs_one, percent_must_add_up, fixed_needs_a_rest or
 * duplicate_recipient; the use cases add unknown_contact, unknown_document,
 * unknown_line, the unreadable values and forbidden.
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
