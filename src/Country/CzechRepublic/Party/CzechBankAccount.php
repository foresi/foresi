<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Country\CzechRepublic\Party;

use App\Party\Domain\DomesticBankAccount;
use App\Party\Domain\Iban;
use App\Party\Domain\InvalidIban;

/**
 * Czech domestic account: an optional prefix of up to 6 digits, a number of
 * 2 to 10 digits and a four-digit bank code ("19-123457/0300"). The IBAN is
 * CZ, the mod-97 check digits, the bank code, the prefix padded to 6 and the
 * number padded to 10 digits.
 */
final class CzechBankAccount implements DomesticBankAccount
{
    public function supports(string $countryCode): bool
    {
        return 'CZ' === $countryCode;
    }

    public function toIban(string $number, string $bankCode): Iban
    {
        $number = (string) preg_replace('/\s+/', '', $number);
        $bankCode = (string) preg_replace('/\s+/', '', $bankCode);
        if (1 !== preg_match('/^(?:(\d{1,6})-)?(\d{2,10})\z/', $number, $parts) || '' === ltrim($parts[2], '0')) {
            throw new InvalidIban('A Czech account number has an optional prefix of up to 6 digits and 2 to 10 digits.');
        }
        if (1 !== preg_match('/^\d{1,4}\z/', $bankCode)) {
            throw new InvalidIban('A Czech bank code has 4 digits.');
        }
        // A spreadsheet that took the code for a number dropped its leading zeros ("0800" became "800").
        $bankCode = str_pad($bankCode, 4, '0', \STR_PAD_LEFT);
        $bban = $bankCode.str_pad($parts[1], 6, '0', \STR_PAD_LEFT).str_pad($parts[2], 10, '0', \STR_PAD_LEFT);

        // The check digits make the number, with "CZ00" moved to its end (C = 12, Z = 35), leave 1 modulo 97.
        $remainder = 0;
        foreach (str_split($bban.'123500') as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return Iban::fromString(\sprintf('CZ%02d%s', 98 - $remainder, $bban));
    }
}
