<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Domain;

/**
 * An IBAN, validated by format and the ISO 7064 mod 97 checksum.
 */
final readonly class Iban
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $raw): self
    {
        $value = strtoupper((string) preg_replace('/\s+/', '', $raw));
        if (1 !== preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $value)) {
            throw new InvalidIban('The IBAN has an invalid format.');
        }

        $rearranged = substr($value, 4).substr($value, 0, 4);
        $remainder = 0;
        foreach (str_split($rearranged) as $char) {
            $digits = (string) (ctype_digit($char) ? $char : \ord($char) - 55);
            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + \ord($digit) - 48) % 97;
            }
        }
        if (1 !== $remainder) {
            throw new InvalidIban('The IBAN checksum is invalid.');
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
