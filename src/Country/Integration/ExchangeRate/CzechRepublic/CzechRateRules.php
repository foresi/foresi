<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\ExchangeRate\CzechRepublic;

use Foresi\ExchangeRate\Contract\RateRules;

/**
 * The legal rate of a Czech firm. The VAT Act (zákon č. 235/2004 Sb., § 4
 * odst. 8, as valid in 2026) converts a foreign currency at the rate valid
 * for the person converting on the day the tax duty arises: either (a1) the
 * foreign-exchange market rate of the ČNB, or (a2) the reference rate of the
 * ECB, where CZK to a currency other than the euro goes through the rate of
 * each to the euro. So a firm may choose the ČNB or the ECB, and the ČNB is
 * the default. Corrections take the rate of the original supply (§ 42, § 45),
 * which the documents keep. Not settled yet: whether a choice must be kept for
 * a whole year, and whether income tax allows the ECB (§ 38 ZDP points to the
 * rate of the Accounting Act).
 */
final readonly class CzechRateRules implements RateRules
{
    /** The name of the ECB source the Exchange rate module offers. */
    private const string ECB = 'ecb';

    public function country(): string
    {
        return 'CZ';
    }

    public function allowedSources(): array
    {
        return [CnbRateSource::NAME, self::ECB];
    }

    public function defaultSource(): string
    {
        return CnbRateSource::NAME;
    }
}
