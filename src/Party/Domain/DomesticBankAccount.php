<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Domain;

/**
 * Country-specific rules for domestic bank account numbers, which other
 * programs export as a number and a bank code instead of an IBAN.
 * Implementations are registered through the container tag "app.domestic_bank_account".
 */
interface DomesticBankAccount
{
    /**
     * @param string $countryCode ISO 3166-1 alpha-2, upper case
     */
    public function supports(string $countryCode): bool;

    /**
     * @throws InvalidIban when the number or the bank code is not one of the country
     */
    public function toIban(string $number, string $bankCode): Iban;
}
