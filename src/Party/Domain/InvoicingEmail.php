<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Domain;

/**
 * An address invoices go to, as the owner enters it. The general e-mail of a
 * contact is not one of them.
 */
final readonly class InvoicingEmail
{
    public string $address;

    public function __construct(string $address, public EmailPurpose $purpose)
    {
        if (EmailPurpose::General === $purpose) {
            throw new \DomainException('The general e-mail is not an invoicing e-mail.');
        }
        $this->address = trim($address);
    }

    /** Two addresses with the same key are the same recipient. */
    public function key(): string
    {
        return self::keyOf($this->address, $this->purpose);
    }

    public static function keyOf(string $address, EmailPurpose $purpose): string
    {
        return $purpose->value.':'.PartyEmail::key($address);
    }
}
