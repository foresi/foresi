<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\Banking\CzechRepublic;

use Foresi\Banking\Contract\BankingCountry;
use Foresi\Country\CzechRepublic\Party\CzechBankAccount;

/**
 * Banking in the Czech Republic: an account is written as its number and bank
 * code, "19-2000145399/0800", and bank days are Prague days.
 */
final readonly class CzechBanking implements BankingCountry
{
    public function supports(string $countryCode): bool
    {
        return 'CZ' === $countryCode;
    }

    public function timezone(): \DateTimeZone
    {
        return new \DateTimeZone('Europe/Prague');
    }

    public function domesticAccount(string $iban): ?string
    {
        $parts = CzechBankAccount::partsOf($iban);

        return null === $parts ? null : $parts['number'].'/'.$parts['bankCode'];
    }
}
