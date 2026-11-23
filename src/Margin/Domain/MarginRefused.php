<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Domain;

/**
 * What a cost refuses to be (ADR 0032): a stable reason, one of
 * whole_needs_one, percent_must_add_up, fixed_needs_a_rest and
 * duplicate_recipient. A client that is no existing contact is checked by
 * the use case, which has the contacts.
 */
final class MarginRefused extends \DomainException
{
    public const string WHOLE_NEEDS_ONE = 'whole_needs_one';
    public const string PERCENT_MUST_ADD_UP = 'percent_must_add_up';
    public const string FIXED_NEEDS_A_REST = 'fixed_needs_a_rest';
    public const string DUPLICATE_RECIPIENT = 'duplicate_recipient';

    public function __construct(private readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
