<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * What a country decides about banking: how its people write an account and
 * the time zone its bank days are in. A country module implements this and is
 * tagged automatically.
 */
#[AutoconfigureTag(self::TAG)]
interface BankingCountry
{
    public const string TAG = 'foresi.banking.country';

    public function supports(string $countryCode): bool;

    public function timezone(): \DateTimeZone;

    /**
     * The account as people of the country write it, if the IBAN is a domestic one.
     */
    public function domesticAccount(string $iban): ?string;
}
