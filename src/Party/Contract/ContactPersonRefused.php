<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract;

/**
 * A contact person that the address book refused to add. Nothing was written. Every field
 * that is wrong is named, so a form shows each problem at its field: name, email, position
 * or person.
 */
final class ContactPersonRefused extends \DomainException
{
    /** A field that must have a value has none. */
    public const string REQUIRED = 'required';
    /** A value is longer than its field takes. */
    public const string TOO_LONG = 'too_long';
    public const string EMAIL = 'email_invalid';
    /** Not a person the company may take: unknown, archived, linked already, or the company is a person itself. */
    public const string PERSON = 'person_invalid';

    /**
     * @param array<string, string> $reasons field => one of the constants
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct('The contact person is refused: '.implode(', ', array_map(static fn (string $field, string $reason): string => $field.' '.$reason, array_keys($reasons), $reasons)).'.');
    }
}
