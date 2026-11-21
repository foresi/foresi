<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\TaxesAndContributions\CzechRepublic;

/**
 * The Czech rules of a sole trader (OSVČ) for 2026, read on 2026-11-20 from
 * the consolidated statutes and the authorities. Act 90/2026 Coll. lowered the
 * minimum social base of a main activity from 40 % to 35 % of the average wage
 * from 1 July 2026: the advances follow the dated value, and the annual
 * settlement uses 35 % for the whole year (ČSSZ). The flat-rate tax (paušální
 * daň, ZDP § 2a, § 38lk) is a monthly amount per band made of an income tax,
 * a social and a health part; band 1 fell with the social minimum from July.
 * Its income limits are those for any activity (ZDP § 2a (5)); higher ones
 * hold when most of the income comes from activities with 80 % or 60 % lump
 * sums.
 */
final class Rules2026
{
    public const int YEAR = 2026;

    public const string ZDP = 'https://www.zakonyprolidi.cz/cs/1992-586';
    public const string SP = 'https://www.zakonyprolidi.cz/cs/1992-589';
    public const string ZP = 'https://www.zakonyprolidi.cz/cs/1992-592';
    public const string PENSION = 'https://www.zakonyprolidi.cz/cs/1995-155';
    public const string SICKNESS = 'https://www.zakonyprolidi.cz/cs/2006-187';
    public const string ACT_90_2026 = 'https://www.cssz.gov.cz/web/cz/-/informace-pro-osvc-o-zmene-pojistneho-na-duchodove-pojisteni-od-1-7-2026';
    public const string AVERAGE_WAGE = 'https://www.pruvodcepodnikanim.cz/clanek/minimalni-zalohy-osvc-2026/';
    public const string HEALTH_MINIMUM = 'https://www.vzp.cz/platci/informace/povinnosti-platcu-metodika/osvc/osvc-minimalni-vyse-zaloh';
    public const string MINIMUM_WAGE = 'https://mpsv.gov.cz/minimalni-mzda-od-ledna-2026-vzroste-o-1-600-korun-na-22-400-korun-mesicne';
    public const string FLAT_RATE = 'https://financnisprava.gov.cz/cs/financni-sprava/media-a-verejnost/tiskove-zpravy-gfr/tiskove-zpravy-2025/pausalni-dan-2026-novinky-terminy';
    public const string FLAT_RATE_JULY = 'https://financnisprava.gov.cz/cs/financni-sprava/media-a-verejnost/tiskove-zpravy-gfr/tiskove-zpravy-2026/poplatnikum-v-prvnim-pasmu-pausalniho-rezimu-snizeni-zalohy';
    public const string FLAT_RATE_PARTS = 'https://www.e15.cz/pausalni-dan-2027';

    /**
     * @return list<RuleValue>
     */
    public static function values(): array
    {
        $year = '2026-01-01';
        $july = '2026-07-01';

        return [
            self::value('average_wage', '48967', $year, self::AVERAGE_WAGE),
            self::value('minimum_wage', '22400', $year, self::MINIMUM_WAGE),
            self::value('social.rate', '29.2', $year, self::SP),
            self::value('social.profit_share', '55', $year, self::SP),
            self::value('social.minimum_share.main', '40', $year, self::SP),
            self::value('social.minimum_share.main', '35', $july, self::ACT_90_2026),
            self::value('social.minimum_share.main.settlement', '35', $year, self::ACT_90_2026),
            self::value('social.minimum_share.secondary', '11', $year, self::SP),
            self::value('social.minimum_share.starting', '25', $year, self::SP),
            self::value('social.maximum_multiple', '48', $year, self::SP),
            self::value('social.decisive_amount', '117521', $year, self::AVERAGE_WAGE),
            self::value('health.rate', '13.5', $year, self::ZP),
            self::value('health.profit_share', '50', $year, self::ZP),
            self::value('health.minimum_share', '50', $year, self::HEALTH_MINIMUM),
            self::value('sickness.rate', '2.7', $year, self::SP),
            self::value('sickness.minimum_base', '9000', $year, self::SICKNESS),
            self::value('tax.rate', '15', $year, self::ZDP),
            self::value('tax.higher_rate', '23', $year, self::ZDP),
            self::value('tax.threshold_multiple', '36', $year, self::ZDP),
            self::value('tax.advance.none_up_to', '30000', $year, self::ZDP),
            self::value('tax.advance.two_up_to', '150000', $year, self::ZDP),
            self::value('tax.advance.two_share', '40', $year, self::ZDP),
            self::value('tax.advance.four_share', '25', $year, self::ZDP),
            self::value('expenses.lump_80.rate', '80', $year, self::ZDP),
            self::value('expenses.lump_80.cap', '1600000', $year, self::ZDP),
            self::value('expenses.lump_60.rate', '60', $year, self::ZDP),
            self::value('expenses.lump_60.cap', '1200000', $year, self::ZDP),
            self::value('expenses.lump_40.rate', '40', $year, self::ZDP),
            self::value('expenses.lump_40.cap', '800000', $year, self::ZDP),
            self::value('expenses.lump_30.rate', '30', $year, self::ZDP),
            self::value('expenses.lump_30.cap', '600000', $year, self::ZDP),
            self::value('credit.taxpayer', '30840', $year, self::ZDP),
            self::value('credit.spouse', '24840', $year, self::ZDP),
            self::value('credit.disability', '2520', $year, self::ZDP),
            self::value('credit.disability_third', '5040', $year, self::ZDP),
            self::value('credit.ztp_p_holder', '16140', $year, self::ZDP),
            self::value('child.first', '15204', $year, self::ZDP),
            self::value('child.second', '22320', $year, self::ZDP),
            self::value('child.third', '27840', $year, self::ZDP),
            self::value('child.bonus_minimum', '100', $year, self::ZDP),
            self::value('child.bonus_income_multiple', '6', $year, self::ZDP),
            self::value('deduction.pension_and_life_cap', '48000', $year, self::ZDP),
            self::value('deduction.housing_loan_cap', '150000', $year, self::ZDP),
            self::value('deduction.gifts_minimum', '1000', $year, self::ZDP),
            self::value('deduction.gifts_minimum_share', '2', $year, self::ZDP),
            self::value('deduction.gifts_maximum_share', '15', $year, self::ZDP),
            self::value('flat_rate.band_1.income_tax', '100', $year, self::FLAT_RATE),
            self::value('flat_rate.band_1.social', '6578', $year, self::FLAT_RATE),
            self::value('flat_rate.band_1.social', '5756', $july, self::FLAT_RATE_JULY),
            self::value('flat_rate.band_1.health', '3306', $year, self::FLAT_RATE),
            self::value('flat_rate.band_1.income_limit', '1000000', $year, self::ZDP),
            self::value('flat_rate.band_2.income_tax', '4963', $year, self::FLAT_RATE),
            self::value('flat_rate.band_2.social', '8191', $year, self::FLAT_RATE_PARTS),
            self::value('flat_rate.band_2.health', '3591', $year, self::FLAT_RATE_PARTS),
            self::value('flat_rate.band_2.income_limit', '1500000', $year, self::ZDP),
            self::value('flat_rate.band_3.income_tax', '9320', $year, self::FLAT_RATE),
            self::value('flat_rate.band_3.social', '12527', $year, self::FLAT_RATE_PARTS),
            self::value('flat_rate.band_3.health', '5292', $year, self::FLAT_RATE_PARTS),
            self::value('flat_rate.band_3.income_limit', '2000000', $year, self::ZDP),
        ];
    }

    private static function value(string $key, string $value, string $from, string $url, bool $unverified = false): RuleValue
    {
        return new RuleValue($key, $value, new \DateTimeImmutable($from), $url, $unverified);
    }
}
