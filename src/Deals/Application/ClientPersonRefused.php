<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application;

/**
 * A contact person the deal's client could not take. Nothing was written. Every field that
 * is wrong is named, so the deal's dialog shows each problem at its field: name, email,
 * position or person.
 */
final class ClientPersonRefused extends \DomainException
{
    public const string REQUIRED = 'required';
    public const string TOO_LONG = 'too_long';
    public const string EMAIL = 'email_invalid';
    /** Not a person the client may take, or no address book to take one from. */
    public const string PERSON = 'person_invalid';

    /**
     * @param array<string, string> $reasons field => one of the constants
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct('The contact person is refused: '.implode(', ', array_map(static fn (string $field, string $reason): string => $field.' '.$reason, array_keys($reasons), $reasons)).'.');
    }
}
