<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Domain\DomesticBankAccount;
use Foresi\Party\Domain\Iban;
use Foresi\Party\Domain\InvalidIban;

/**
 * Reads a bank account as people write it: an IBAN, or a domestic number with
 * a bank code ("19-2000145399/0800") of a country a country module knows.
 */
final readonly class AccountNumbers
{
    /** "19-2000145399/0800": a domestic number, a slash and a bank code. */
    private const string DOMESTIC = '~^([0-9\s-]+)/\s*([0-9]{1,4})$~D';

    /**
     * @param iterable<DomesticBankAccount> $domesticAccounts
     */
    public function __construct(private iterable $domesticAccounts)
    {
    }

    /**
     * @param string $countryCode the country whose domestic numbers are read; any country a module knows when empty
     *
     * @return Iban|null null when it is neither an IBAN nor a domestic number of the country
     */
    public function toIban(string $raw, string $countryCode): ?Iban
    {
        try {
            return Iban::fromString($raw);
        } catch (InvalidIban) {
        }
        if (1 !== preg_match(self::DOMESTIC, trim($raw), $parts)) {
            return null;
        }
        foreach ($this->domesticAccounts as $accounts) {
            if ('' === $countryCode || $accounts->supports($countryCode)) {
                try {
                    return $accounts->toIban($parts[1], $parts[2]);
                } catch (InvalidIban) {
                }
            }
        }

        return null;
    }
}
