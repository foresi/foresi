<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\TaxesAndContributions\CzechRepublic;

/**
 * What changes for a Czech sole trader in 2027, as known on 2026-11-20; every
 * other value holds on from 2026. The average wage comes from Government
 * Regulation 177/2026 Coll. by the statute's formula, and the decisive amount
 * and the sickness minimum are derived from it before ČSSZ and MPSV publish
 * them, so they are unverified. Act 180/2026 Coll. adds the student, school-fee
 * and EET credits. Band 1 of the flat-rate tax follows the new minimums: 100 +
 * 6,073 + 3,488 = 9,661 by the statute, where some sources say 9,662, so its
 * social and health parts are unverified; bands 2 and 3 stay. The surcharge
 * of 1,400 a month for not recording sales (Act 180/2026) is not counted.
 */
final class Rules2027
{
    public const int YEAR = 2027;

    public const string REGULATION_177_2026 = 'https://www.epravo.cz/_dataPublic/sbirky/2026/Sb_2026_177_PZZ.pdf';
    public const string ACT_180_2026 = 'https://www.zakonyprolidi.cz/cs/2026-180';
    public const string MINIMUM_WAGE = 'https://mpsv.gov.cz/minimalni-mzda-v-roce-2027-vzroste-na-24-900-korun';
    public const string FLAT_RATE = 'https://norman.finance/cz/blog/pausalni-dan';
    public const string HEALTH_MINIMUM = 'https://www.forvismazars.com/cz/cs/prehledy/forvis-mazars-v-mediich/archiv-2026/kolik-zaplatite-na-zalohach-v-roce-2027';
    public const string SICKNESS_MINIMUM = 'https://www.businessinfo.cz/clanky/minimalni-zalohy-pro-osvc-na-povinna-pojisteni-opet-narostou-ceka-se-na-odhad-prumerne-mzdy/';

    /**
     * @return list<RuleValue>
     */
    public static function values(): array
    {
        $year = new \DateTimeImmutable('2027-01-01');

        return [
            new RuleValue('average_wage', '51663', $year, self::REGULATION_177_2026, true),
            new RuleValue('minimum_wage', '24900', $year, self::MINIMUM_WAGE),
            new RuleValue('social.decisive_amount', '123991', $year, Rules2026::PENSION, true),
            new RuleValue('sickness.minimum_base', '10000', $year, self::SICKNESS_MINIMUM, true),
            new RuleValue('credit.student', '4020', $year, self::ACT_180_2026),
            new RuleValue('credit.school_fees', '24900', $year, self::ACT_180_2026),
            new RuleValue('credit.eet', '5000', $year, self::ACT_180_2026),
            new RuleValue('flat_rate.band_1.social', '6073', $year, self::FLAT_RATE, true),
            new RuleValue('flat_rate.band_1.health', '3488', $year, self::HEALTH_MINIMUM, true),
        ];
    }
}
