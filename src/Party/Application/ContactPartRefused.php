<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

/**
 * A contact person, bank account or address of a contact that the rules do not
 * allow. Nothing was written. Every field that is wrong is named, so a form can
 * show each problem at its field.
 */
final class ContactPartRefused extends \DomainException
{
    public const string REQUIRED = 'required';
    public const string TOO_LONG = 'too_long';
    public const string EMAIL = 'email';
    public const string COUNTRY = 'country';
    public const string ACCOUNT_NUMBER = 'account_number';
    public const string BIC = 'bic';
    public const string DUPLICATE_ACCOUNT = 'duplicate_account';
    public const string ADDRESS_TYPE = 'address_type';

    /**
     * @param array<string, string> $reasons field => one of the constants
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct('The contact part is refused: '.implode(', ', array_map(static fn (string $field, string $reason): string => $field.' '.$reason, array_keys($reasons), $reasons)).'.');
    }
}
