<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Domain;

/**
 * An EU VAT identification number: two-letter prefix plus 2 to 12 alphanumerics.
 */
final readonly class VatId
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $raw): self
    {
        $value = strtoupper((string) preg_replace('/\s+/', '', $raw));
        if (1 !== preg_match('/^([A-Z]{2})([A-Z0-9]{2,12})$/', $value, $m)) {
            throw new InvalidVatId('A VAT id is a two-letter country prefix followed by 2 to 12 letters or digits.');
        }
        if ('CZ' === $m[1] && 1 !== preg_match('/^[0-9]{8,10}$/', $m[2])) {
            throw new InvalidVatId('A Czech VAT id has 8 to 10 digits after the prefix.');
        }

        return new self($value);
    }

    public function country(): string
    {
        return substr($this->value, 0, 2);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
